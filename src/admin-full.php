<?php
ini_set('display_errors','Off');
ini_set('display_warnings', 'Off');
date_default_timezone_set('UTC');

require('vendor/autoload.php');

$app = new Silex\Application();

// include server-side configuration
include('config.php');

$app['DRAFT_CONTENT'] = 'sitecake-content';
$app['PUBLIC_IMAGES'] = 'images';
$app['PUBLIC_FILES'] = 'files';
$app['SERVER_BASE'] = 'sitecake/2.1.0/server';
$app['SERVICE_URL'] = $app['SERVER_BASE'] . '/admin.php';
$app['SITECAKE_EDITOR_LOGIN_URL'] = 'sitecake/' .
	'2.1.0/client/publicmanager/publicmanager.nocache.js';
$app['SITECAKE_EDITOR_EDIT_URL'] = 'sitecake/2.1.0/client/' .
	'contentmanager/contentmanager.nocache.js';
$app['CONFIG_URL'] = 'sitecake/editor.cfg';

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Silex\Application;

// global error handler
$app->error(function (\LogicException $e, $code) {
    return new Response("Exception: " . $e->getMessage() . "\n\r" . $e->getTraceAsString(), 500);
});

// CORS enabled
$app->after(function (Request $request, Response $response) {
    $response->headers->set('Access-Control-Allow-Origin', '*');
});

use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local as AdapterLocal;
use League\Flysystem\Adapter\Ftp as AdapterFtp;

// configure the abstract file system
if ($app['filesystem.adapter'] == 'local') {
	$app['fs'] = $app->share(function($app) {
		return new Filesystem(new AdapterLocal(realpath(__DIR__ . '/../../../')));
	});
} else if ($app['filesystem.adapter'] == 'ftp') {
	$app['fs'] = $app->share(function($app) {
		return new Filesystem(new AdapterFtp($app['filesystem.adapter.config']));
	});	
} else {
	dia('Unsupported filesystem.adapter ' + $app['filesystem.adapter'] + '. Supported types are local and ftp. Please check the configuration.');
}

// add application specific filesystem plugins
$app['fs']->addPlugin(new Sitecake\Filesystem\EnsureDirectory);
$app['fs']->addPlugin(new Sitecake\Filesystem\ListPatternPaths);
$app['fs']->addPlugin(new Sitecake\Filesystem\RandomDirectory);
$app['fs']->addPlugin(new Sitecake\Filesystem\CopyPaths);
$app['fs']->addPlugin(new Sitecake\Filesystem\DeletePaths);

$app->register(new Silex\Provider\SessionServiceProvider());

$app->register(new Silex\Provider\TranslationServiceProvider(), array(
    'locale_fallbacks' => array('en'),
));

$app['translator'] = $app->share($app->extend('translator', function($translator, $app) {
    $translator->addLoader('yaml', new Symfony\Component\Translation\Loader\YamlFileLoader());
    $translator->addResource('yaml', __DIR__.'/locales/en.yml', 'en');
    return $translator;
}));

$app['auth'] = $app->share(function($app) {
	return new Sitecake\Auth($app['fs'], '/sitecake/credentials.php');
});

$app['site'] = $app->share(function($app) {
	return new Sitecake\Site($app['fs']);
});

$app['flock'] = $app->share(function($app) {
	return new Sitecake\FileLock($app['fs'], $app['site']->tmpPath());
});

$app['sm'] = $app->share(function($app) {
	return new Sitecake\SessionManager($app['session'], $app['flock'], $app['auth']);
});

$app['renderer'] = $app->share(function($app) {
	return new Sitecake\Renderer($app['site'], $app);
});

$app['services'] = $app->share(function($app) {
	return new Sitecake\Services($app);
});

$app['router'] = $app->share(function($app) {
	return new Sitecake\Router($app['sm'], $app);
});

$app['debug'] = true;
//$app['session']->set('loggedin', true);

$app->match('/', function(Application $app, Request $request) {
	// check if GD is present
	if (!extension_loaded('gd')) {
		throw new \Exception("GD lib (PHP extension) is required, but it's not loaded.");
	}
	
	$app['services']->load();
	return $app['router']->route($request);
});

$app->run();