<?php
namespace GarageMinder\API\Models;

use GarageMinder\API\Core\Database;

class Reminder
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all reminders for a user (across all vehicles)
     */
    public function getByUser(int $userId): array
    {
        $reminders = $this->db->fetchAll(
            "SELECT r.*, v.year, v.make, v.model, v.odometer as current_odometer,
                    v.id as vehicle_id
             FROM reminders r
             JOIN vehicles v ON r.vehicle_id = v.id
             WHERE v.user_id = ?
             ORDER BY r.due_date ASC, r.due_mileage ASC",
            [$userId]
        );

        return array_map([$this, 'formatReminder'], $reminders);
    }

    /**
     * Get reminders for a specific vehicle
     */
    public function getByVehicle(int $vehicleId, int $userId): array
    {
        // Verify ownership
        $vehicle = $this->db->fetchOne(
            "SELECT id FROM vehicles WHERE id = ? AND user_id = ?",
            [$vehicleId, $userId]
        );

        if (!$vehicle) return [];

        $reminders = $this->db->fetchAll(
            "SELECT r.*, v.year, v.make, v.model, v.odometer as current_odometer,
                    v.id as vehicle_id
             FROM reminders r
             JOIN vehicles v ON r.vehicle_id = v.id
             WHERE r.vehicle_id = ?
             ORDER BY r.due_date ASC, r.due_mileage ASC",
            [$vehicleId]
        );

        return array_map([$this, 'formatReminder'], $reminders);
    }

    /**
     * Get single reminder by ID
     */
    public function getById(int $reminderId, int $userId): ?array
    {
        $reminder = $this->db->fetchOne(
            "SELECT r.*, v.year, v.make, v.model, v.odometer as current_odometer,
                    v.id as vehicle_id
             FROM reminders r
             JOIN vehicles v ON r.vehicle_id = v.id
             WHERE r.id = ? AND v.user_id = ?",
            [$reminderId, $userId]
        );

        return $reminder ? $this->formatReminder($reminder) : null;
    }

    /**
     * Get due/overdue reminders for a user (all vehicles)
     */
    public function getDueByUser(int $userId, int $windowDays = null): array
    {
        $windowDays = $windowDays ?? REMINDERS_DUE_WINDOW_DAYS;
        $futureDate = date('Y-m-d', strtotime("+{$windowDays} days"));
        $today = date('Y-m-d');

        $reminders = $this->db->fetchAll(
            "SELECT r.*, v.year, v.make, v.model, v.odometer as current_odometer,
                    v.id as vehicle_id
             FROM reminders r
             JOIN vehicles v ON r.vehicle_id = v.id
             WHERE v.user_id = ?
               AND (
                   (r.due_date IS NOT NULL AND r.due_date <= ?)
                   OR (r.due_mileage IS NOT NULL AND r.due_mileage <= v.odometer)
               )
             ORDER BY 
               CASE 
                 WHEN r.due_date < ? THEN 0
                 WHEN r.due_mileage <= v.odometer THEN 0
                 ELSE 1
               END ASC,
               r.due_date ASC",
            [$userId, $futureDate, $today]
        );

        return array_map(function($r) use ($today) {
            $formatted = $this->formatReminder($r);
            $formatted['is_overdue'] = $this->isOverdue($r, $today);
            $formatted['urgency'] = $this->calculateUrgency($r, $today);
            return $formatted;
        }, $reminders);
    }

    /**
     * Get due/overdue reminders for a specific vehicle
     */
    public function getDueByVehicle(int $vehicleId, int $userId, int $windowDays = null): array
    {
        $windowDays = $windowDays ?? REMINDERS_DUE_WINDOW_DAYS;
        $futureDate = date('Y-m-d', strtotime("+{$windowDays} days"));
        $today = date('Y-m-d');

        // Verify ownership
        $vehicle = $this->db->fetchOne(
            "SELECT id FROM vehicles WHERE id = ? AND user_id = ?",
            [$vehicleId, $userId]
        );
        if (!$vehicle) return [];

        $reminders = $this->db->fetchAll(
            "SELECT r.*, v.year, v.make, v.model, v.odometer as current_odometer,
                    v.id as vehicle_id
             FROM reminders r
             JOIN vehicles v ON r.vehicle_id = v.id
             WHERE r.vehicle_id = ?
               AND (
                   (r.due_date IS NOT NULL AND r.due_date <= ?)
                   OR (r.due_mileage IS NOT NULL AND r.due_mileage <= v.odometer)
               )
             ORDER BY r.due_date ASC",
            [$vehicleId, $futureDate]
        );

        return array_map(function($r) use ($today) {
            $formatted = $this->formatReminder($r);
            $formatted['is_overdue'] = $this->isOverdue($r, $today);
            $formatted['urgency'] = $this->calculateUrgency($r, $today);
            return $formatted;
        }, $reminders);
    }

    /**
     * Format reminder for API response
     */
    private function formatReminder(array $r): array
    {
        return [
            'id'              => (int) $r['id'],
            'vehicle_id'      => (int) $r['vehicle_id'],
            'vehicle_name'    => trim(($r['year'] ?? '') . ' ' . $r['make'] . ' ' . $r['model']),
            'service_type'    => $r['service_type'] ?? null,
            'description'     => $r['description'] ?? null,
            'due_date'        => $r['due_date'] ?? null,
            'due_mileage'     => $r['due_mileage'] ? (int) $r['due_mileage'] : null,
            'current_odometer'=> (int) ($r['current_odometer'] ?? 0),
            'notes'           => $r['notes'] ?? null,
            'is_recurring'    => (bool) ($r['is_recurring'] ?? false),
            'recurrence_interval' => $r['recurrence_interval'] ?? null,
            'recurrence_miles'    => $r['recurrence_miles'] ? (int) $r['recurrence_miles'] : null,
            'created_at'      => $r['created_at'] ?? null,
            'updated_at'      => $r['updated_at'] ?? null,
        ];
    }

    private function isOverdue(array $r, string $today): bool
    {
        if (!empty($r['due_date']) && $r['due_date'] < $today) return true;
        if (!empty($r['due_mileage']) && (int)$r['due_mileage'] <= (int)($r['current_odometer'] ?? 0)) return true;
        return false;
    }

    private function calculateUrgency(array $r, string $today): string
    {
        $overdue = $this->isOverdue($r, $today);
        if ($overdue) return 'overdue';

        if (!empty($r['due_date'])) {
            $daysUntil = (strtotime($r['due_date']) - strtotime($today)) / 86400;
            if ($daysUntil <= 7) return 'urgent';
            if ($daysUntil <= 30) return 'upcoming';
        }

        if (!empty($r['due_mileage'])) {
            $milesUntil = (int)$r['due_mileage'] - (int)($r['current_odometer'] ?? 0);
            if ($milesUntil <= 500) return 'urgent';
            if ($milesUntil <= 2000) return 'upcoming';
        }

        return 'normal';
    }
}
