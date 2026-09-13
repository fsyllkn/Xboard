<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NativeRelayTest extends TestCase
{
    use RefreshDatabase;

    public function test_managed_relay_config_uses_child_port_and_parent_service_endpoint(): void
    {
        $machine = $this->makeMachine('relay-a');
        $parent = $this->makeServer([
            'name' => 'B real service',
            'type' => Server::TYPE_VLESS,
            'host' => '203.0.113.20',
            'port' => 22222,
            'server_port' => 22222,
        ]);
        $relay = $this->makeServer([
            'name' => 'A relay entry',
            'type' => Server::TYPE_VLESS,
            'host' => '198.51.100.10',
            'port' => 23456,
            'server_port' => 22222,
            'parent_id' => $parent->id,
            'machine_id' => $machine->id,
        ]);

        $config = ServerService::buildNodeConfig($relay);

        $this->assertSame('relay', $config['mode']);
        $this->assertSame(Server::TYPE_VLESS, $config['protocol']);
        $this->assertSame(23456, $config['relay']['listen_port']);
        $this->assertSame('203.0.113.20', $config['relay']['target_host']);
        $this->assertSame(22222, $config['relay']['target_port']);
        $this->assertSame(['tcp'], $config['relay']['networks']);
    }

    public function test_parent_only_node_keeps_legacy_external_relay_behaviour(): void
    {
        $parent = $this->makeServer([
            'name' => 'legacy parent',
            'type' => Server::TYPE_SOCKS,
            'protocol_settings' => ['tls' => 0],
        ]);
        $legacy = $this->makeServer([
            'name' => 'legacy gost entry',
            'type' => Server::TYPE_SOCKS,
            'parent_id' => $parent->id,
            'machine_id' => null,
            'protocol_settings' => ['tls' => 0],
        ]);

        $config = ServerService::buildNodeConfig($legacy);

        $this->assertSame('service', $config['mode']);
        $this->assertArrayNotHasKey('relay', $config);
    }

    public function test_udp_and_dual_stack_protocols_map_to_correct_relay_transports(): void
    {
        $machine = $this->makeMachine('relay-a');

        $hyParent = $this->makeServer([
            'name' => 'hy parent',
            'type' => Server::TYPE_HYSTERIA,
            'server_port' => 30001,
        ]);
        $hyRelay = $this->makeServer([
            'name' => 'hy relay',
            'type' => Server::TYPE_HYSTERIA,
            'port' => 31001,
            'server_port' => 30001,
            'parent_id' => $hyParent->id,
            'machine_id' => $machine->id,
        ]);
        $this->assertSame(['udp'], ServerService::buildNodeConfig($hyRelay)['relay']['networks']);

        $ssParent = $this->makeServer([
            'name' => 'ss parent',
            'type' => Server::TYPE_SHADOWSOCKS,
            'server_port' => 30002,
        ]);
        $ssRelay = $this->makeServer([
            'name' => 'ss relay',
            'type' => Server::TYPE_SHADOWSOCKS,
            'port' => 31002,
            'server_port' => 30002,
            'parent_id' => $ssParent->id,
            'machine_id' => $machine->id,
        ]);
        $this->assertSame(['tcp', 'udp'], ServerService::buildNodeConfig($ssRelay)['relay']['networks']);
    }

    public function test_disabled_parent_removes_managed_relay_from_machine_tasks(): void
    {
        $machine = $this->makeMachine('relay-a');
        $parent = $this->makeServer([
            'name' => 'B real service',
            'type' => Server::TYPE_VLESS,
        ]);
        $relay = $this->makeServer([
            'name' => 'A relay',
            'type' => Server::TYPE_VLESS,
            'parent_id' => $parent->id,
            'machine_id' => $machine->id,
        ]);

        $this->assertTrue(ServerService::getMachineNodes($machine)->contains('id', $relay->id));

        $parent->enabled = false;
        $parent->saveQuietly();

        $this->assertFalse(ServerService::getMachineNodes($machine)->contains('id', $relay->id));
    }

    public function test_root_machine_node_remains_a_normal_service_task(): void
    {
        $machine = $this->makeMachine('service-b');
        $service = $this->makeServer([
            'name' => 'B service',
            'type' => Server::TYPE_SOCKS,
            'machine_id' => $machine->id,
            'protocol_settings' => ['tls' => 0],
        ]);

        $config = ServerService::buildNodeConfig($service);

        $this->assertSame('service', $config['mode']);
        $this->assertSame($service->server_port, $config['server_port']);
    }

    private function makeMachine(string $name): ServerMachine
    {
        return ServerMachine::create([
            'name' => $name,
            'token' => $name . '-token',
            'is_active' => true,
        ]);
    }

    private function makeServer(array $overrides = []): Server
    {
        return Server::create(array_merge([
            'name' => 'test-node',
            'type' => Server::TYPE_VLESS,
            'host' => '127.0.0.1',
            'port' => 22222,
            'server_port' => 22222,
            'rate' => 1,
            'group_ids' => [],
            'route_ids' => [],
            'protocol_settings' => [],
            'show' => true,
            'enabled' => true,
        ], $overrides));
    }
}
