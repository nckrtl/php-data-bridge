<?php
// Only used by the local regression server; never install this fixture with the bridge.
require getenv('BRIDGE_TEST_ROOT').'/tests/vendor/autoload.php';
use Symfony\Component\HttpFoundation\{Request, Response, RequestStack};
use Symfony\Component\HttpKernel\{HttpKernel, Kernel, KernelInterface};
use Symfony\Component\HttpKernel\Controller\{ControllerResolver, ArgumentResolver};
use Symfony\Component\EventDispatcher\EventDispatcher;
use PhpDataBridge\Recorder;

class RequestState { public static int $count = 0; }
define('REQUEST_STATE_TEST', true);
if (($_SERVER['REQUEST_URI'] ?? '') === '/state') {
    echo json_encode(['count' => ++RequestState::$count, 'pid' => getmypid()]);
    exit;
}
$env = $_SERVER['HTTP_X_TEST_ENV'] ?? 'local';
$framework = $_SERVER['HTTP_X_TEST_FRAMEWORK'] ?? 'symfony';
if (isset($_SERVER['HTTP_X_TEST_TOOLBAR'])) {
    class ExistingToolbarServiceProvider {}
    class_alias(ExistingToolbarServiceProvider::class, 'NckRtl\\Toolbar\\ToolbarServiceProvider');
}
$controller = static function () use ($framework): Response {
    if (isset($_SERVER['HTTP_X_TEST_TOOLBAR']) && str_starts_with($_SERVER['REQUEST_URI'], '/_toolbar/requests/')) {
        return new Response('existing-toolbar-history');
    }
    if ($framework === 'laravel') {
        $connection = new Illuminate\Database\SQLiteConnection(new PDO('sqlite::memory:'), 'test');
        $query = static fn () => $connection->select('select :name as name', ['name' => 'private-binding']);
    } else {
        $connection = Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $query = static fn () => $connection->executeQuery('select :name as name', ['name' => 'private-binding']);
    }
    $count = str_starts_with($_SERVER['REQUEST_URI'], '/many') ? 503 : 1;
    for ($i = 0; $i < $count; $i++) { $query(); }
    $twig = new Twig\Environment(new Twig\Loader\ArrayLoader(['page' => '<html><body>Hello</body></html>']));
    $body = $twig->render('page', ['secret' => 'private-prop', 'object' => new class implements Stringable {
        public function __toString(): string { throw new RuntimeException('Context conversion ran application code'); }
    }]);
    if (str_starts_with($_SERVER['REQUEST_URI'], '/abort')) { exit('aborted'); }
    return new Response($body, 200, ['X-Recorded-Queries' => (string) count(Recorder::$queries), 'X-Worker-Pid' => (string) getmypid(), 'X-Test-Store' => PhpDataBridge\Store::directory()]);
};
if ($framework === 'laravel') {
    $app = new Illuminate\Foundation\Application(getenv('BRIDGE_TEST_APP'));
    Illuminate\Foundation\Bootstrap\LoadConfiguration::alwaysUse(static fn () => ['app' => ['env' => $env, 'debug' => true, 'timezone' => 'UTC', 'locale' => 'en']]);
    $router = $app['router'];
    $router->any('/{path?}', $controller)->where('path', '.*');
    $kernel = new class($app, $router) extends Illuminate\Foundation\Http\Kernel {
        protected $bootstrappers = [Illuminate\Foundation\Bootstrap\LoadConfiguration::class];
    };
    $kernel->handle(Illuminate\Http\Request::capture())->send();
} else {
    $request = Request::createFromGlobals();
    $request->attributes->set('_controller', $controller);
    // Exercise the Kernel::boot observer through a minimal subclass.
    $kernel = new class($env, true) extends Kernel {
        public function registerBundles(): iterable { return []; }
        public function registerContainerConfiguration(Symfony\Component\Config\Loader\LoaderInterface $loader): void {}
        public function boot(): void { $this->booted = true; }
    };
    if ($env !== 'missing') { $kernel->boot(); }
    (new HttpKernel(new EventDispatcher(), new ControllerResolver(), new RequestStack(), new ArgumentResolver()))->handle($request)->send();
}
