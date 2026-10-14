<?php

// Standalone regression: php tests/Regression/device-state-json.php
// Run in its own PHP process; no Laravel bootstrap or live Redis is needed.
namespace Illuminate\Support\Facades {
    final class Redis
    {
        public static array $hashes = [];

        public static function hgetall(string $key): array
        {
            return self::$hashes[$key] ?? [];
        }
    }
}

namespace {
    use App\Services\DeviceStateService;
    use Illuminate\Support\Facades\Redis;

    require __DIR__ . '/../../app/Services/DeviceStateService.php';

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    $a = '192.0.2.1';
    $b = '198.51.100.2';
    $v6 = '2001:db8::1';
    $cases = [
        'continuous IPv4' => [[$a, $b], [$a, $b]],
        'duplicate creates sparse indexes' => [[$a, $a, $b], [$a, $b]],
        'trailing duplicate keeps continuous indexes' => [[$a, $b, $a], [$a, $b]],
        'all duplicate' => [[$a, $a, $a], [$a]],
        'multiple gaps' => [[$a, $a, $b, $b, $v6], [$a, $b, $v6]],
        'IPv6 duplicate' => [[$v6, $v6, '::1'], [$v6, '::1']],
        'mixed IPv4 IPv6' => [[$a, $v6, $a, '::1'], [$a, $v6, '::1']],
        'sparse input indexes' => [[2 => $a, 8 => $a, 15 => $v6], [$a, $v6]],
        'empty hash' => [[], []],
    ];

    $service = new DeviceStateService();
    foreach ($cases as $name => [$input, $expected]) {
        $hash = [];
        foreach ($input as $nodeId => $ip) {
            // Same IP at different nodes remains distinct in the Redis hash.
            $hash[($nodeId + 1) . ':' . $ip] = time();
        }
        Redis::$hashes = ['user_devices:42' => $hash];
        $devices = $service->getUsersDevices([42]);
        check($devices === ($expected === [] ? [] : [42 => $expected]), "$name: values/order");
        check($service->getDeviceCount(42) === count($expected), "$name: device count");
        $json = json_encode(['event' => 'sync.devices', 'data' => ['users' => $devices]], JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if ($expected !== []) {
            check(array_is_list($devices[42]), "$name: continuous zero-based indexes");
            check(is_array($decoded->data->users->{'42'}), "$name: JSON array, not object");
            check($decoded->data->users->{'42'} === $expected, "$name: JSON round trip");
        } else {
            // Existing behavior omits users with no active IPs; it does not emit 42: [].
            check($json === '{"event":"sync.devices","data":{"users":[]}}', "$name: empty JSON unchanged");
        }
        echo "PASS $name\n";
    }

    Redis::$hashes = [
        'user_devices:42' => ['1:' . $a => time() - 301],
        'user_devices:99' => ['1:' . $v6 => time()],
    ];
    check($service->getUsersDevices([42, 43, 99]) === [99 => [$v6]], 'expired/missing user omitted and user ID preserved');
    check($service->getUsersDevices([]) === [], 'empty user list');
    check(json_encode($service->getUsersDevices([]), JSON_THROW_ON_ERROR) === '[]', 'empty list JSON');
    echo "PASS expired/missing users and empty user list\n";

    // Demonstrate the precise pre-fix failure and contiguous control.
    check(array_keys(array_unique([$a, $a, $b])) === [0, 2], 'pre-fix sparse indexes');
    check(is_object(json_decode(json_encode(array_unique([$a, $a, $b]), JSON_THROW_ON_ERROR))), 'pre-fix JSON object');
    check(is_array(json_decode(json_encode(array_unique([$a, $b, $a]), JSON_THROW_ON_ERROR))), 'pre-fix contiguous JSON array');
    echo "PASS pre-fix failure reproduced and contiguous control\n";
}
