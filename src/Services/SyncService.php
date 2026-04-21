<?php

namespace Guarzo\Seat\WandererSync\Services;

use Guarzo\Seat\WandererSync\Exceptions\BadApiKeyException;
use Guarzo\Seat\WandererSync\Exceptions\NotFoundException;
use Guarzo\Seat\WandererSync\Exceptions\WandererApiException;
use Guarzo\Seat\WandererSync\Models\WandererAccessListInstance;
use Guarzo\Seat\WandererSync\Support\SyncResult;
use Psr\Log\LoggerInterface;

final class SyncService
{
    public function __construct(
        private readonly UserCharacterResolver $resolver,
        private readonly LoggerInterface $logger,
    ) {}

    public function syncInstance(WandererAccessListInstance $instance): SyncResult
    {
        $allowed = $this->resolver->allowedCharacterIdsForInstance($instance)->unique()->values();

        $client = $instance->client();
        $current = $client->fetchMembers()->unique()->values();

        $toAdd = $allowed->diff($current)->values();
        $toRemove = $current->diff($allowed)->values();

        $removed = 0;
        $failed = [];

        // Removes first.
        foreach ($toRemove as $charId) {
            try {
                $client->removeMember($charId);
                $removed++;
            } catch (NotFoundException) {
                // Already gone — count as success.
                $removed++;
            } catch (BadApiKeyException $e) {
                throw $e;
            } catch (WandererApiException $e) {
                $this->logger->error('Failed to remove character from ACL', [
                    'instance_id' => $instance->id,
                    'character_id' => $charId,
                    'status' => $e->status,
                    'message' => $e->getMessage(),
                ]);
                $failed[] = $charId;
            }
        }

        $added = 0;
        foreach ($toAdd as $charId) {
            try {
                $client->addMember($charId);
                $added++;
            } catch (BadApiKeyException $e) {
                throw $e;
            } catch (WandererApiException $e) {
                $this->logger->error('Failed to add character to ACL', [
                    'instance_id' => $instance->id,
                    'character_id' => $charId,
                    'status' => $e->status,
                    'message' => $e->getMessage(),
                ]);
                $failed[] = $charId;
            }
        }

        return new SyncResult(added: $added, removed: $removed, failed: $failed);
    }
}
