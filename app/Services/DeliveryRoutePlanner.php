<?php

namespace App\Services;

use App\Models\Delivery;
use Illuminate\Support\Collection;

class DeliveryRoutePlanner
{
    public function plan(Collection $deliveries): array
    {
        $stops = $deliveries->map(function (Delivery $task) {
            $map = $task->linkedCustomerMapUrl();
            $point = $this->mapPoint($map);
            $order = $task->sale ?? $task->rental;
            $address = $task->linkedCustomerAddress();
            $destination = $point
                ? implode(',', $point)
                : ($address ? implode(', ', array_filter([
                    $address, $task->linkedCustomerCity(), $order?->deliveryContactState(), $order?->deliveryContactPincode(),
                ])) : null);

            return [
                'task' => $task,
                'point' => $point,
                'address' => $address,
                'destination' => $destination,
                'navigation' => $destination ? 'https://www.google.com/maps/dir/?'.http_build_query([
                    'api' => 1, 'destination' => $destination, 'travelmode' => 'driving',
                ], '', '&', PHP_QUERY_RFC3986) : null,
            ];
        });
        $route = [];
        $previous = null;
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

    public function mapLegs(array $stops): array
    {
        $destinations = array_values(array_filter(array_column($stops, 'destination')));
        $legs = [];
        $origin = null;
        foreach (array_chunk($destinations, 4) as $chunk) {
            $last = array_pop($chunk);
            $url = 'https://www.google.com/maps/dir/?'.http_build_query(array_filter([
                'api' => 1,
                'origin' => $origin,
                'destination' => $last,
                'waypoints' => implode('|', $chunk),
                'travelmode' => 'driving',
            ]), '', '&', PHP_QUERY_RFC3986);
            $legs[] = strlen($url) <= 2048 ? $url : null;
            $origin = $last;
        }

        return $legs;
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

        return sin(($toLat - $fromLat) / 2) ** 2
            + cos($fromLat) * cos($toLat) * sin(($toLng - $fromLng) / 2) ** 2;
    }
}
