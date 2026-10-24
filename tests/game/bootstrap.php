<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function testApp(string $prefix): \think\App
{
    if (!preg_match('/^test_login_[a-f0-9]{12}_$/D', $prefix)) throw new RuntimeException('Unsafe test prefix');
    $app = new \think\App(dirname(__DIR__, 2));
    $app->initialize();
    $config = $app->config->get('database');
    // Optional isolated loopback instance for portable integration test runs.
    $testPort = getenv('GAME_TEST_DB_PORT');
    if ($testPort !== false && $testPort !== '') {
        $testDatabase = (string) getenv('GAME_TEST_DB_NAME');
        if (!ctype_digit($testPort) || (int) $testPort < 1024 || (int) $testPort > 65535
            || !preg_match('/^test_[a-z0-9_]{1,48}$/D', $testDatabase)) throw new RuntimeException('Unsafe isolated test database');
        $config['connections']['mysql'] = array_replace($config['connections']['mysql'], [
            'hostname' => '127.0.0.1', 'hostport' => $testPort, 'database' => $testDatabase,
            'username' => 'root', 'password' => (string) (getenv('GAME_TEST_DB_PASSWORD') ?: ''),
        ]);
    }
    $config['connections']['mysql']['prefix'] = $prefix;
    $config['connections']['mysql']['trigger_sql'] = false;
    $app->config->set($config, 'database');
    return $app;
}

function testProvider(string $openid = 'test-openid', string $provider = 'wechat_mini', string $appId = 'wx3dd63318911f5012'): \app\common\game\platform\IdentityProvider
{
    // This test fixture is outside the public root and is never registered in the HTTP app.
    return new class($openid, $provider, $appId) implements \app\common\game\platform\IdentityProvider {
        public int $calls = 0;
        public function __construct(private string $openid, private string $provider, private string $appId) {}
        public function exchange(string $code): array
        {
            $this->calls++;
            return ['provider' => $this->provider, 'app_id' => $this->appId, 'openid' => $this->openid, 'unionid' => null];
        }
    };
}
