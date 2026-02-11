<?php
namespace GarageMinder\API\Models;

use GarageMinder\API\Core\Database;

class Device
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Register or update a device
     */
    public function registerDevice(int $userId, array $data): array
    {
        $deviceId = $data['device_id'] ?? null;
        if (!$deviceId) throw new \InvalidArgumentException('device_id is required');

        $existing = $this->db->fetchOne(
            "SELECT id FROM api_devices WHERE user_id = ? AND device_id = ?",
            [$userId, $deviceId]
        );

        $deviceData = [
            'user_id'     => $userId,
            'device_id'   => $deviceId,
            'platform'    => $data['platform'] ?? 'unknown',
            'device_name' => $data['device_name'] ?? null,
            'device_model'=> $data['device_model'] ?? null,
            'os_version'  => $data['os_version'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'push_token'  => $data['push_token'] ?? null,
            'last_active'  => date('Y-m-d H:i:s'),
            'is_active'   => 1,
        ];

        if ($existing) {
            unset($deviceData['user_id'], $deviceData['device_id']);
            $this->db->update('api_devices', $deviceData, 'id = ?', [$existing['id']]);
            return array_merge($deviceData, ['id' => (int)$existing['id'], 'status' => 'updated']);
        } else {
            $id = $this->db->insert('api_devices', $deviceData);
            return array_merge($deviceData, ['id' => $id, 'status' => 'registered']);
        }
    }

    /**
     * Get user's registered devices
     */
    public function getByUser(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT id, device_id, platform, device_name, device_model,
                    os_version, app_version, last_active, is_active, created_at
             FROM api_devices WHERE user_id = ? ORDER BY last_active DESC",
            [$userId]
        );
    }
}
