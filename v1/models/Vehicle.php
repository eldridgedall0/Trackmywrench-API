<?php
/**
 * Vehicle Model - Matched to actual GarageMinder DB schema
 * 
 * ACTUAL vehicles table columns:
 *   id              varchar(64)  PK  (e.g. "v_1766985747930_q4x1fvgxzde")
 *   user_id         varchar(64)      (WP user ID stored as string)
 *   name            varchar(255)     (display name)
 *   current_odo     int              (current odometer reading)
 *   vin             varchar(64)
 *   plate           varchar(64)
 *   year            int
 *   make            varchar(100)
 *   model           varchar(100)
 *   engine          varchar(255)
 *   body_class      varchar(100)
 *   photo_path      varchar(500)
 *   insurance_expiry     date
 *   registration_expiry  date
 */

namespace GarageMinder\API\Models;

use GarageMinder\API\Core\Database;

class Vehicle
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all vehicles for a user
     */
    public function getByUser(int $userId): array
    {
        $vehicles = $this->db->fetchAll(
            "SELECT id, user_id, name, current_odo, vin, plate,
                    year, make, model, engine, body_class, photo_path,
                    insurance_expiry, registration_expiry
             FROM vehicles WHERE user_id = ?
             ORDER BY name ASC",
            [$userId]
        );

        return array_map([$this, 'formatVehicle'], $vehicles);
    }

    /**
     * Get a single vehicle by ID (with user ownership check)
     */
    public function getById(string $vehicleId, int $userId): ?array
    {
        $vehicle = $this->db->fetchOne(
            "SELECT id, user_id, name, current_odo, vin, plate,
                    year, make, model, engine, body_class, photo_path,
                    insurance_expiry, registration_expiry
             FROM vehicles WHERE id = ? AND user_id = ?",
            [$vehicleId, $userId]
        );

        return $vehicle ? $this->formatVehicle($vehicle) : null;
    }

    /**
     * Update vehicle odometer
     */
    public function updateOdometer(string $vehicleId, int $userId, int $newOdometer): bool
    {
        $vehicle = $this->getById($vehicleId, $userId);
        if (!$vehicle) return false;

        if ($newOdometer < $vehicle['odometer']) {
            return false;
        }

        $affected = $this->db->execute(
            "UPDATE vehicles SET current_odo = ? WHERE id = ? AND user_id = ?",
            [$newOdometer, $vehicleId, $userId]
        );

        return $affected > 0;
    }

    /**
     * Batch update odometers (for sync push)
     */
    public function batchUpdateOdometers(int $userId, array $updates): array
    {
        $results = [];
        $db = $this->db;
        $db->beginTransaction();

        try {
            foreach ($updates as $update) {
                $vehicleId = $update['id'] ?? '';
                $newOdometer = (int) ($update['odometer'] ?? 0);

                if (empty($vehicleId) || $newOdometer <= 0) {
                    $results[] = [
                        'id' => $vehicleId,
                        'success' => false,
                        'error' => 'Invalid vehicle ID or odometer value',
                    ];
                    continue;
                }

                $vehicle = $this->getById($vehicleId, $userId);
                if (!$vehicle) {
                    $results[] = [
                        'id' => $vehicleId,
                        'success' => false,
                        'error' => 'Vehicle not found or not owned by user',
                    ];
                    continue;
                }

                if ($newOdometer < $vehicle['odometer']) {
                    $results[] = [
                        'id' => $vehicleId,
                        'success' => false,
                        'error' => "New odometer ({$newOdometer}) less than current ({$vehicle['odometer']})",
                    ];
                    continue;
                }

                $increase = $newOdometer - $vehicle['odometer'];
                if ($increase > SYNC_MAX_ODOMETER_JUMP) {
                    $results[] = [
                        'id' => $vehicleId,
                        'success' => false,
                        'error' => "Odometer increase of {$increase} exceeds maximum allowed",
                    ];
                    continue;
                }

                $db->execute(
                    "UPDATE vehicles SET current_odo = ? WHERE id = ? AND user_id = ?",
                    [$newOdometer, $vehicleId, $userId]
                );

                $results[] = [
                    'id' => $vehicleId,
                    'success' => true,
                    'previous_odometer' => $vehicle['odometer'],
                    'new_odometer' => $newOdometer,
                    'increase' => $increase,
                ];
            }

            $db->commit();
        } catch (\Exception $e) {
            $db->rollback();
            throw $e;
        }

        return $results;
    }

    /**
     * Format vehicle for API response
     * Maps DB column names → clean API field names
     */
    private function formatVehicle(array $vehicle): array
    {
        $displayName = $vehicle['name'];
        if (empty($displayName) && ($vehicle['make'] || $vehicle['model'])) {
            $displayName = trim(($vehicle['year'] ?? '') . ' ' . ($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? ''));
        }

        return [
            'id'            => $vehicle['id'],
            'user_id'       => $vehicle['user_id'],
            'name'          => $vehicle['name'],
            'display_name'  => $displayName,
            'vin'           => $vehicle['vin'],
            'plate'         => $vehicle['plate'],
            'year'          => $vehicle['year'] ? (int) $vehicle['year'] : null,
            'make'          => $vehicle['make'],
            'model'         => $vehicle['model'],
            'engine'        => $vehicle['engine'],
            'body_class'    => $vehicle['body_class'],
            'odometer'      => (int) ($vehicle['current_odo'] ?? 0),  // DB: current_odo → API: odometer
            'photo_path'    => $vehicle['photo_path'],
            'insurance_expiry'     => $vehicle['insurance_expiry'],
            'registration_expiry'  => $vehicle['registration_expiry'],
        ];
    }
}
