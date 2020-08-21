<?php

namespace Livewire;

use Illuminate\Support\Str;
use Illuminate\Support\Fluent;
use Livewire\Testing\TestableLivewire;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Dusk\Browser;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Exceptions\MountMethodMissingException;
use Livewire\HydrationMiddleware\AddAttributesToRootTagOfHtml;

class LivewireManager
{
    use RegistersHydrationMiddleware;

    protected $componentAliases = [];
    protected $customComponentResolver;
    protected $listeners = [];

    public static $isLivewireRequestTestingOverride;

    public function component($alias, $viewClass = null)
    {
        if (is_null($viewClass)) {
            $viewClass = $alias;
            $alias = (new $viewClass('fake-id'))->getName();
        }

        $this->componentAliases[$alias] = $viewClass;
    }

    public function componentResolver($callback)
    {
        $this->customComponentResolver = $callback;
    }

    public function getComponentClass($alias)
    {
        $finder = app()->make(LivewireComponentsFinder::class);

        $class = false;

        if ($this->customComponentResolver) {
            // A developer can hijack the way Livewire finds components using Livewire::componentResolver();
            $class = call_user_func($this->customComponentResolver, $alias);
        }

        $class = $class ?: (
            // Let's first check if the user registered the component using:
            // Livewire::component('name', [Livewire component class]);
            // If not, we'll look in the auto-discovery manifest.
            $this->componentAliases[$alias] ?? $finder->find($alias)
        );

        $class = $class ?: (
            // If none of the above worked, our last-ditch effort will be
            // to re-generate the auto-discovery manifest and look again.
            $finder->build()->find($alias)
        );

        throw_unless($class, new ComponentNotFoundException(
            "Unable to find component: [{$alias}]"
        ));

        return $class;
    }

    public function activate($component, $id)
    {
        $componentClass = $this->getComponentClass($component);

        throw_unless(class_exists($componentClass), new ComponentNotFoundException(
            "Component [{$component}] class not found: [{$componentClass}]"
        ));

        return new $componentClass($id);
    }

    public function mount($name, $params = [])
    {
        // This is if a user doesn't pass params, BUT passes key() as the second argument.
        if (is_string($params)) $params = [];

        $id = Str::random(20);

        // Allow instantiating Livewire components directly from classes.
        if ($name instanceof Component) {
            $id = $name->id;

            $instance = $name;

            $name = $instance->getName();
        } elseif (class_exists($name)) {
            $instance = new $name($id);
            // Set the name to the computed name, so that the full namespace
            // isn't leaked to the front-end.
            $name = $instance->getName();
        } else {
            $instance = $this->activate($name, $id);
        }

        $request = new Request([
            'fingerprint' => [
                'id' => $id,
                'name' => $name,
                'locale' => app()->getLocale(),
            ],
            'updates' => [],
            'serverMemo' => [],
        ]);

        $this->initialHydrate($instance, $request);

        $this->performMount($instance, $params);

        $html = $instance->output();

        $response = Response::fromRequest($request, $html);

        $this->initialDehydrate($instance, $response);

        $response->embedThyselfInHtml();

        $this->dispatch('mounted', $response);

        return $response->toInitialResonse();
    }

    public function dummyMount($id, $tagName)
    {
        return "<{$tagName} wire:id=\"{$id}\"></{$tagName}>";
    }

    public function test($name, $params = [])
    {
        return new TestableLivewire($name, $params);
    }

    public function visit($browser, $class, $queryString = '')
    {
        $url = '/livewire-dusk/'.urlencode($class).$queryString;

        return $browser->visit($url);
    }

    public function actingAs(Authenticatable $user, $driver = null)
    {
        if (isset($user->wasRecentlyCreated) && $user->wasRecentlyCreated) {
            $user->wasRecentlyCreated = false;
        }

        auth()->guard($driver)->setUser($user);

        auth()->shouldUse($driver);

        return $this;
    }

    public function styles($options = [])
    {
        $debug = config('app.debug');

        $styles = $this->cssAssets();

        // HTML Label.
        $html = $debug ? ['<!-- Livewire Styles -->'] : [];

        // CSS assets.
        $html[] = $debug ? $styles : $this->minify($styles);

        return implode("\n", $html);
    }

    public function scripts($options = [])
    {
        $debug = config('app.debug');

        $scripts = $this->javaScriptAssets($options);

        // HTML Label.
        $html = $debug ? ['<!-- Livewire Scripts -->'] : [];

        // JavaScript assets.
        $html[] = $debug ? $scripts : $this->minify($scripts);

        return implode("\n", $html);
    }

    protected function cssAssets()
    {
        return <<<HTML
<style>
    [wire\:loading], [wire\:loading\.delay] {
        display: none;
    }

    [wire\:offline] {
        display: none;
    }

    [wire\:dirty]:not(textarea):not(input):not(select) {
        display: none;
    }
</style>
HTML;
    }

    protected function javaScriptAssets($options)
    {
        $jsonEncodedOptions = $options ? json_encode($options) : '';

        $appUrl = config('livewire.asset_url', rtrim($options['asset_url'] ?? '', '/'));

        $csrf = csrf_token();

        $manifest = json_decode(file_get_contents(__DIR__.'/../dist/manifest.json'), true);
        $versionedFileName = $manifest['/livewire.js'];

        // Default to dynamic `livewire.js` (served by a Laravel route).
        $fullAssetPath = "{$appUrl}/livewire{$versionedFileName}";
        $assetWarning = null;

        // Use static assets if they have been published
        if (file_exists(public_path('vendor/livewire'))) {
            $publishedManifest = json_decode(file_get_contents(public_path('vendor/livewire/manifest.json')), true);
            $versionedFileName = $publishedManifest['/livewire.js'];

            $fullAssetPath = ($this->isOnVapor() ? config('app.asset_url') : $appUrl).'/vendor/livewire'.$versionedFileName;

            if ($manifest !== $publishedManifest) {
                $assetWarning = <<<'HTML'
<script>
    console.warn("Livewire: The published Livewire assets are out of date\n See: https://laravel-livewire.com/docs/installation/")
</script>
HTML;
            }
        }

        $nonce = isset($options['nonce']) ? " nonce=\"{$options['nonce']}\"" : '';

        // Adding semicolons for this JavaScript is important,
        // because it will be minified in production.
        return <<<HTML
{$assetWarning}
<script src="{$fullAssetPath}" data-turbolinks-eval="false"></script>
<script data-turbolinks-eval="false"{$nonce}>
    if (window.livewire) {
        console.warn('Livewire: It looks like Livewire\'s @livewireScripts JavaScript assets have already been loaded. Make sure you aren\'t loading them twice.')
    }

    window.livewire = new Livewire({$jsonEncodedOptions});
    window.Livewire = window.livewire
    window.livewire_app_url = '{$appUrl}';
    window.livewire_token = '{$csrf}';

    /* Make Alpine wait until Livewire is finished rendering to do its thing. */
    window.deferLoadingAlpine = function (callback) {
        window.addEventListener('livewire:load', function () {
            callback();
        });
    };

    document.addEventListener("DOMContentLoaded", function () {
        window.livewire.start();
    });

    var firstTime = true;
    document.addEventListener("turbolinks:load", function() {
        /* We only want this handler to run AFTER the first load. */
        if  (firstTime) {
            firstTime = false;
            return;
        }

        window.livewire.restart();
    });

    document.addEventListener("turbolinks:before-cache", function() {
        document.querySelectorAll('[wire\\\:id]').forEach(function(el) {
            const component = el.__livewire;

            const dataObject = {
                data: component.data,
                events: component.listeners,
                children: component.children,
                checksum: component.checksum,
                locale: component.locale,
                name: component.name,
                errorBag: component.errorBag,
                redirectTo: component.redirectTo,
            };

            el.setAttribute('wire:initial-data', JSON.stringify(dataObject));
        });
    });
</script>
HTML;
    }

    protected function minify($subject)
    {
        return preg_replace('~(\v|\t|\s{2,})~m', '', $subject);
    }

    public function isLivewireRequest()
    {
        if (static::$isLivewireRequestTestingOverride) {
            return true;
        }

        return request()->hasHeader('X-Livewire');
    }

    public function getRootElementTagName($dom)
    {
        preg_match('/<([a-zA-Z0-9\-]*)/', $dom, $matches, PREG_OFFSET_CAPTURE);

        return $matches[1][0];
    }

    public function dispatch($event, ...$params)
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener(...$params);
        }
    }

    public function listen($event, $callback)
    {
        $this->listeners[$event] ?? $this->listeners[$event] = [];

        $this->listeners[$event][] = $callback;
    }

    public function isOnVapor()
    {
        return ($_ENV['SERVER_SOFTWARE'] ?? null) === 'vapor';
    }

    private function performMount($instance, $params)
    {
        if (! method_exists($instance, 'mount') && count($params) > 0) {
            throw new MountMethodMissingException($instance->getName());
        }

        if (! method_exists($instance, 'mount')) return;

        ImplicitlyBoundMethod::call(app(), [$instance, 'mount'], $params);
    }
}
