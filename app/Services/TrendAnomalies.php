<?php

declare(strict_types=1);

namespace App\Services;

class TrendAnomalies
{
    public const MIN_PRIORS = 4;

    public const MAX_WINDOW = 8;

    public const SIGMA_FLOOR = 0.25;

    public const SIGMA_FLOOR_HOURS = self::SIGMA_FLOOR;

    public static function detect(array $values): array
    {
        $anomalies = [];

        for ($i = 0, $n = count($values); $i < $n; $i++) {
            $x = $values[$i];

            if ($x === null) {
                continue;
            }

            $window = [];
            for ($j = $i - 1; $j >= 0 && count($window) < self::MAX_WINDOW; $j--) {
                if ($values[$j] !== null) {
                    $window[] = (float) $values[$j];
                }
            }

            if (count($window) < self::MIN_PRIORS) {
                continue;
            }

            $mean = array_sum($window) / count($window);
            $variance = 0.0;
            foreach ($window as $w) {
                $variance += ($w - $mean) ** 2;
            }
            $variance /= count($window);
            $sigma = sqrt($variance);

            $sigmaEff = max($sigma, self::SIGMA_FLOOR);
            $z = ($x - $mean) / $sigmaEff;

            if (abs($z) > 2) {
                $anomalies[] = [
                    'index'      => $i,
                    'value'       => (float) $x,
                    'mean'        => round($mean, 2),
                    'sigma'       => round($sigma, 2),
                    'sigma_eff'   => round($sigmaEff, 2),
                    'z'           => round($z, 1),
                    'direction'   => $x > $mean ? 'high' : 'low',
                ];
            }
        }

        return $anomalies;
    }
}
