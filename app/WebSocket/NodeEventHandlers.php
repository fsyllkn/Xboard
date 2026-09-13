<?php

namespace App\WebSocket;

use App\Models\Server;
use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\Services\ServerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Workerman\Connection\TcpConnection;

class NodeEventHandlers
{
    /**
     * Handle pong heartbeat
     */
    public static function handlePong(TcpConnection $conn, int $nodeId, array $data = []): void
    {
        Cache::put("node_ws_alive:{$nodeId}", true, 86400);
    }

    /**
     * Handle node status update
     */
    public static function handleNodeStatus(TcpConnection $conn, int $nodeId, array $data): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        $nodeType = strtoupper($node->type);
        Cache::put(\App\Utils\CacheKey::get('SERVER_' . $nodeType . '_LAST_CHECK_AT', $nodeId), time(), 3600);
        ServerService::updateMetrics($node, $data);

        Log::debug("[WS] Node#{$nodeId} status updated");
    }

    /**
     * Handle device report from node.
     */
    public static function handleDeviceReport(TcpConnection $conn, int $nodeId, array $data): void
    {
        $node = Server::find($nodeId);
        if (!$node || self::isManagedRelay($node)) {
            return;
        }

        $service = app(DeviceStateService::class);

        if (isset($data['devices']) && is_array($data['devices'])) {
            $data = $data['devices'];
        }

        $oldDevices = $service->getNodeDevices($nodeId);
        $removedUsers = array_diff_key($oldDevices, $data);
        $newDevices = [];

        foreach ($data as $userId => $ips) {
            if (is_numeric($userId) && is_array($ips)) {
                $newDevices[(int) $userId] = $ips;
            }
        }

        foreach ($removedUsers as $userId => $ips) {
            $service->removeNodeDevices($nodeId, $userId);
            $service->notifyUpdate($userId);
        }

        foreach ($newDevices as $userId => $ips) {
            $service->setDevices($userId, $nodeId, $ips);
        }

        Redis::sadd('device:push_pending_nodes', $nodeId);

        Log::debug("[WS] Node#{$nodeId} synced " . count($newDevices) . " users, removed " . count($removedUsers));
    }

    /**
     * Handle device state request from node.
     */
    public static function handleDeviceRequest(TcpConnection $conn, int $nodeId, array $data = []): void
    {
        $node = Server::find($nodeId);
        if (!$node || self::isManagedRelay($node)) {
            return;
        }

        $users = ServerService::getAvailableUsers($node);
        $userIds = $users->pluck('id')->toArray();

        $service = app(DeviceStateService::class);
        $devices = $service->getUsersDevices($userIds);

        NodeRegistry::send($nodeId, 'sync.devices', [
            'users' => $devices,
        ]);

        Log::debug("[WS] Node#{$nodeId} requested devices, sent " . count($devices) . " users");
    }

    /**
     * Push device state to node.
     */
    public static function pushDeviceStateToNode(int $nodeId, DeviceStateService $service): void
    {
        $node = Server::find($nodeId);
        if (!$node || self::isManagedRelay($node)) {
            return;
        }

        $users = ServerService::getAvailableUsers($node);
        $userIds = $users->pluck('id')->toArray();
        $devices = $service->getUsersDevices($userIds);

        NodeRegistry::send($nodeId, 'sync.devices', [
            'users' => $devices
        ]);

        Log::debug("[WS] Pushed device state to node#{$nodeId}: " . count($devices) . " users");
    }

    /**
     * Push full config + users to newly connected node.
     * Managed relays receive config only; authentication/accounting stays on the parent service.
     */
    public static function pushFullSync(TcpConnection $conn, Server $node): void
    {
        $nodeId = (int) $node->id;

        $config = ServerService::buildNodeConfig($node);
        NodeRegistry::send($nodeId, 'sync.config', [
            'config' => $config,
        ]);

        if (self::isManagedRelay($node)) {
            Log::info("[WS] Relay config pushed to node#{$nodeId}");
            return;
        }

        $users = ServerService::getAvailableUsers($node)->toArray();
        NodeRegistry::send($nodeId, 'sync.users', [
            'users' => $users,
        ]);

        Log::info("[WS] Full sync pushed to node#{$nodeId}", [
            'users' => count($users),
        ]);
    }

    private static function isManagedRelay(Server $node): bool
    {
        return !empty($node->parent_id) && !empty($node->machine_id);
    }
}
