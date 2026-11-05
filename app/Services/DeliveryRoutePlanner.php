<?php

namespace App\Services;

use App\Models\Delivery;
use Illuminate\Support\Collection;

class DeliveryRoutePlanner
{
    public const ESTIMATED_SPEED_KMH = 25;

    public const ROAD_DISTANCE_FACTOR = 1.3;

    public function plan(Collection $deliveries, ?array $warehousePoint = null, ?string $origin = null): array
    {
        $stops = $deliveries->map(function (Delivery $task) use ($warehousePoint, $origin) {
            $map = $task->linkedCustomerMapUrl();
            $point = $this->mapPoint($map);
            $order = $task->sale ?? $task->rental;
            $address = $task->linkedCustomerAddress();
            $destination = $point
                ? implode(',', $point)
                : ($address ? implode(', ', array_filter([
                    $address, $task->linkedCustomerCity(), $order?->deliveryContactState(), $order?->deliveryContactPincode(),
                ])) : null);

            $distance = $point !== null && $warehousePoint !== null ? $this->distance($warehousePoint, $point) : null;

            return [
                'task' => $task,
                'point' => $point,
                'address' => $address,
                'destination' => $destination,
                'navigation' => $destination ? $this->directions($origin, $destination) : null,
                'distance_km' => $distance,
                'travel_minutes' => $distance === null ? null : (int) ceil($distance * self::ROAD_DISTANCE_FACTOR / self::ESTIMATED_SPEED_KMH * 60),
            ];
        });
        $route = [];
        $previous = $warehousePoint;
        foreach ($stops->groupBy(fn ($stop) => $stop['task']->scheduled_at->format('H:i')) as $block) {
            // Stops with the same appointment time can be reordered. Unknown locations keep their original positions.
            $known = $block->filter(fn ($stop) => $stop['point'] !== null)->values()->all();
            foreach ($block as $stop) {
                if ($stop['point'] === null) {
                    $route[] = $stop;
                    continue;
                }

                $next = array_key_first($known);
                if ($previous !== null) {
                    $best = INF;
                    foreach ($known as $key => $candidate) {
                        $distance = $this->distance($previous, $candidate['point']);
                        if ($distance < $best) {
                            $best = $distance;
                            $next = $key;
                        }
                    }
                }
                $route[] = $known[$next];
                $previous = $known[$next]['point'];
                unset($known[$next]);
            }
        }

        return $route;
    }

    public function mapLegs(array $stops, ?string $origin = null): array
    {
        $destinations = array_values(array_filter(array_column($stops, 'destination')));
        $legs = [];
        foreach (array_chunk($destinations, 4) as $chunk) {
            $last = array_pop($chunk);
            $legs[] = $this->directions($origin, $last, $chunk);
            $origin = $last;
        }

        return $legs;
    }

    public function applyManualOrder(array $stops, array $ids): array
    {
        $remaining = collect($stops)->keyBy(fn ($stop) => $stop['task']->id)->all();
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($remaining[$id])) {
                $ordered[] = $remaining[$id];
                unset($remaining[$id]);
            }
        }

        return array_merge($ordered, array_values($remaining));
    }

    private function directions(?string $origin, string $destination, array $waypoints = []): ?string
    {
        $url = 'https://www.google.com/maps/dir/?'.http_build_query(array_filter([
            'api' => 1,
            'origin' => $origin,
            'destination' => $destination,
            'waypoints' => implode('|', $waypoints),
            'travelmode' => 'driving',
        ]), '', '&', PHP_QUERY_RFC3986);

        return strlen($url) <= 2048 ? $url : null;
    }

    private function mapPoint(?string $map): ?array
    {
        $parts = parse_url($map ?? '');
        if (($parts['scheme'] ?? null) !== 'https'
            || !in_array(strtolower($parts['host'] ?? ''), ['maps.google.com', 'www.google.com', 'google.com'], true)) {
            return null;
        }

        parse_str($parts['query'] ?? '', $parameters);
        $pin = $parameters['query'] ?? $parameters['q'] ?? null;
        $pair = '(-?\d{1,3}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)';
        $found = is_string($pin) && preg_match('/^'.$pair.'$/D', trim($pin), $match);
        if (!$found) {
            // Google place links can identify the pin separately from the map viewport centre.
            $path = $parts['path'] ?? '';
            $found = str_starts_with($path, '/maps/place/')
                && preg_match('/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)(?=!|$)/', $path, $match);
        }
        if (!$found || abs((float) $match[1]) > 90 || abs((float) $match[2]) > 180) {
            return null;
        }

        return [(float) $match[1], (float) $match[2]];
    }

    private function distance(array $from, array $to): float
    {
        [$fromLat, $fromLng] = array_map(deg2rad(...), $from);
        [$toLat, $toLng] = array_map(deg2rad(...), $to);

        $haversine = sin(($toLat - $fromLat) / 2) ** 2
            + cos($fromLat) * cos($toLat) * sin(($toLng - $fromLng) / 2) ** 2;

        return 2 * 6371 * asin(min(1, sqrt($haversine)));
    }
}
