<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Role;
use App\Models\SupportTicket;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns audit rows into display rows for the Audit Logs page and the staff
 * notification feed: adds `entity_model` (short model name) and
 * `entity_name` (a human label), batch-loaded per entity type.
 */
class AuditPresenter
{
    public static function withEntityNames(Collection $logs): Collection
    {
        $idsByType = $logs->groupBy('entity_type')->map(fn ($rows) => $rows->pluck('entity_id')->filter()->unique()->all());

        $names = [];
        $lookups = [
            User::class => fn ($ids) => User::withTrashed()->whereIn('id', $ids)->pluck('name', 'id'),
            SupportTicket::class => fn ($ids) => collect($ids)->mapWithKeys(fn ($id) => [$id => 'TKT-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT)]),
            Campaign::class => fn ($ids) => Campaign::withTrashed()->whereIn('id', $ids)->pluck('title', 'id'),
            Role::class => fn ($ids) => Role::whereIn('id', $ids)->pluck('label', 'id'),
            Task::class => fn ($ids) => Task::withTrashed()->whereIn('id', $ids)->pluck('title', 'id'),
            TaskSubmission::class => fn ($ids) => TaskSubmission::with('task:id,title')->whereIn('id', $ids)->get()
                ->mapWithKeys(fn ($s) => [$s->id => $s->task?->title]),
        ];
        foreach ($idsByType as $type => $ids) {
            if ($ids && isset($lookups[$type])) {
                try {
                    $names[$type] = $lookups[$type]($ids);
                } catch (\Throwable) {
                    // A renamed column must never break the list.
                }
            }
        }

        return $logs->map(function (AuditLog $log) use ($names) {
            $row = $log->toArray();
            $row['entity_model'] = class_basename((string) $log->entity_type);
            $row['entity_name'] = isset($names[$log->entity_type]) ? ($names[$log->entity_type][$log->entity_id] ?? null) : null;

            return $row;
        })->values();
    }
}
