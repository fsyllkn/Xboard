<?php

namespace App\Observers;

use App\Models\Server;
use App\Services\NodeSyncService;

class ServerObserver
{
    public bool $afterCommit = true;

    /**
     * A copied node must not immediately deploy onto the source machine.
     * The admin explicitly selects a machine after editing the copied entry.
     */
    public function replicating(Server $server): void
    {
        $server->machine_id = null;
    }

    public function created(Server $server): void
    {
        $this->notifyMachineNodesChanged($server->machine_id);
    }

    public function updated(Server $server): void
    {
        $oldParentId = $server->getOriginal('parent_id');
        $oldMachineId = $server->getOriginal('machine_id');
        $oldManagedRelay = !empty($oldParentId) && !empty($oldMachineId);
        $newManagedRelay = !empty($server->parent_id) && !empty($server->machine_id);

        $machineMoved = $server->wasChanged('machine_id');
        $taskModeChanged = $oldManagedRelay !== $newManagedRelay;
        $requiresTaskRestart = $machineMoved || $taskModeChanged;

        if ($server->wasChanged('group_ids')) {
            if (!$requiresTaskRestart) {
                NodeSyncService::notifyFullSync($server->id);
            }
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
            // Service <-> Relay transitions and machine moves are rebuilt via sync.nodes.
            // In-place Relay target/listener changes still use sync.config for hot update.
            if (!$requiresTaskRestart) {
                NodeSyncService::notifyConfigUpdated($server->id);
            }
        }

        if ($server->wasChanged(['machine_id', 'enabled', 'parent_id', 'type'])) {
            $this->notifyMachineChange(
                $server->machine_id,
                $oldMachineId
            );
        }

        // A root service's endpoint/state is authoritative for all of its managed relays.
        if ($server->wasChanged(['host', 'server_port', 'protocol_settings', 'type', 'enabled', 'parent_id'])) {
            // A parent type/topology change may make children invalid, so rediscover
            // their machines instead of trying to hot-load an incompatible config.
            $topologyChanged = $server->wasChanged(['enabled', 'parent_id', 'type']);
            $this->notifyManagedRelayChildren($server->id, $topologyChanged);
        }
    }

    public function deleted(Server $server): void
    {
        $this->notifyMachineChange(null, $server->getOriginal('machine_id') ?: $server->machine_id);
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
