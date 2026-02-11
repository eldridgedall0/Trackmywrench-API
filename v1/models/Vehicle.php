<?php
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
            "SELECT id, user_id, vin, year, make, model, trim, 
                    odometer, is_active, notes, photo_path,
                    created_at, updated_at
             FROM gm_vehicles WHERE user_id = ? ORDER BY is_active DESC, updated_at DESC",
            [$userId]
        );

        return array_map([$this, 'formatVehicle'], $vehicles);
    }

    /**
     * Get a single vehicle by ID (with user ownership check)
     */
    public function getById(int $vehicleId, int $userId): ?array
    {
        $vehicle = $this->db->fetchOne(
            "SELECT id, user_id, vin, year, make, model, trim,
                    odometer, is_active, notes, photo_path,
                    created_at, updated_at
             FROM gm_vehicles WHERE id = ? AND user_id = ?",
            [$vehicleId, $userId]
        );

        return $vehicle ? $this->formatVehicle($vehicle) : null;
    }

    /**
     * Update vehicle odometer
     */
    public function updateOdometer(int $vehicleId, int $userId, int $newOdometer): bool
    {
        $vehicle = $this->getById($vehicleId, $userId);
        if (!$vehicle) return false;

        // Sanity check: odometer should not decrease
        if ($newOdometer < $vehicle['odometer']) {
            return false;
        }

        $affected = $this->db->execute(
            "UPDATE gm_vehicles SET odometer = ?, updated_at = NOW() WHERE id = ? AND user_id = ?",
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
                $vehicleId = (int) ($update['id'] ?? 0);
                $newOdometer = (int) ($update['odometer'] ?? 0);

                if ($vehicleId <= 0 || $newOdometer <= 0) {
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
                    "UPDATE gm_vehicles SET odometer = ?, updated_at = NOW() WHERE id = ? AND user_id = ?",
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
     */
    private function formatVehicle(array $vehicle): array
    {
        return [
            'id'          => (int) $vehicle['id'],
            'user_id'     => (int) $vehicle['user_id'],
            'vin'         => $vehicle['vin'],
            'year'        => $vehicle['year'] ? (int) $vehicle['year'] : null,
            'make'        => $vehicle['make'],
            'model'       => $vehicle['model'],
            'trim'        => $vehicle['trim'],
            'display_name'=> trim(($vehicle['year'] ?? '') . ' ' . $vehicle['make'] . ' ' . $vehicle['model']),
            'odometer'    => (int) $vehicle['odometer'],
            'is_active'   => (bool) $vehicle['is_active'],
            'notes'       => $vehicle['notes'],
            'photo_path'  => $vehicle['photo_path'],
            'created_at'  => $vehicle['created_at'],
            'updated_at'  => $vehicle['updated_at'],
        ];
    }
}
