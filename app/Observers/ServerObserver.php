<?php

namespace App\Observers;

use App\Models\Server;
use App\Services\NodeSyncService;

class ServerObserver
{
    public bool $afterCommit = true;

    public function created(Server $server): void
    {
        $this->notifyMachineNodesChanged($server->machine_id);
    }

    public function updated(Server $server): void
    {
        if ($server->wasChanged('group_ids')) {
            NodeSyncService::notifyFullSync($server->id);
        } elseif ($server->wasChanged([
            'port',
            'server_port',
            'parent_id',
            'protocol_settings',
            'type',
            'route_ids',
            'custom_outbounds',
            'custom_routes',
            'cert_config',
        ])) {
            NodeSyncService::notifyConfigUpdated($server->id);
        }

        // A mode or ownership change must rediscover the task, not merely hot-update it.
        if ($server->wasChanged(['machine_id', 'enabled', 'parent_id', 'type'])) {
            $this->notifyMachineChange(
                $server->machine_id,
                $server->getOriginal('machine_id')
            );
        }

        // A root service's endpoint/state is authoritative for all of its managed relays.
        if ($server->wasChanged(['host', 'server_port', 'protocol_settings', 'type', 'enabled', 'parent_id'])) {
            $topologyChanged = $server->wasChanged(['enabled', 'parent_id']);
            $this->notifyManagedRelayChildren($server->id, $topologyChanged);
        }
    }

    public function deleted(Server $server): void
    {
        $this->notifyMachineChange(null, $server->getOriginal('machine_id') ?: $server->machine_id);

        // If a real service is deleted, every managed relay pointing at it must disappear.
        $this->notifyManagedRelayChildren($server->id, true);
    }

    private function notifyManagedRelayChildren(int $parentId, bool $topologyChanged): void
    {
        $children = Server::where('parent_id', $parentId)
            ->whereNotNull('machine_id')
            ->get(['id', 'machine_id']);

        if ($children->isEmpty()) {
            return;
        }

        $machines = [];
        foreach ($children as $child) {
            if (!$topologyChanged) {
                NodeSyncService::notifyConfigUpdated($child->id);
            }
            if ($child->machine_id) {
                $machines[(int) $child->machine_id] = true;
            }
        }

        if ($topologyChanged) {
            foreach (array_keys($machines) as $machineId) {
                NodeSyncService::notifyMachineNodesChanged($machineId);
            }
        }
    }

    private function notifyMachineChange(?int $newMachineId, ?int $oldMachineId): void
    {
        $notified = [];

        if ($newMachineId) {
            NodeSyncService::notifyMachineNodesChanged($newMachineId);
            $notified[] = $newMachineId;
        }

        if ($oldMachineId && !in_array($oldMachineId, $notified, true)) {
            NodeSyncService::notifyMachineNodesChanged($oldMachineId);
        }
    }

    private function notifyMachineNodesChanged(?int $machineId): void
    {
        if ($machineId) {
            NodeSyncService::notifyMachineNodesChanged($machineId);
        }
    }
}
