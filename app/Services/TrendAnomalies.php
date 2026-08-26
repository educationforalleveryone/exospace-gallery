<?php

declare(strict_types=1);

namespace App\Services;

/**
 * ITERATION 7 — >2σ anomaly annotations for the TTFE trend chart.
 *
 * The gap: a TTFE line that drifts up after a release is invisible to
 * the operator unless they already suspect something. Release markers
 * (Iteration 6) annotate *causes we already know about*; this detector
 * annotates *effects we don't* — a week that sits >2σ above the trailing
 * mean so an operator can ask "what changed that week?" without a
 * release to blame and without eyeballing a flat line for a spike.
 *
 * Algorithm (deliberately transparent — the operator can recompute it
 * by hand):
 *
 *   For each point i in the series (must have ≥4 prior non-null points):
 *     window   = the most recent 8 prior non-null values (or fewer
 *                if the trend is younger than 9 weeks — the rule
 *                self-starts; min 4 priors is the floor below which
 *                σ is meaningless on weekly samples).
 *     mean     = arithmetic mean of window
 *     sigma    = population standard deviation of window
 *               (sqrt(sum((x-mean)^2)/n)).
 *     sigma_eff = max(sigma, 0.25)  ← flat-baseline guard: a perfectly
 *                flat window would otherwise divide-by-σ-zero; the
 *                floor is the data's own display resolution (0.1h)
 *                rounded up, so on a flat baseline a deviation must
 *                exceed 2 × 0.25 = 0.5h (30 minutes) to flag — a
 *                noise-level 0.1h blip won't.
 *     z        = (x_i - mean) / sigma_eff
 *     flagged  = abs(z) > 2
 *     direction = 'high' (worse — slower onboarding) | 'low' (better)
 *
 * Null weeks are skipped (gap-tolerant — a null ttfe_avg is a week
 * with no publishers, not a 0h TTFE); they break the trailing window
 * naturally: the detector looks back through PRIOR non-null points
 * only, never future ones (the trailing mean must precede the point).
 *
 * This is descriptive analytics only — the same series the chart
 * already shows, no new data collected. Pure PHP, no dependencies,
 * portable across SQLite/MySQL (the math is in-process).
 */
class TrendAnomalies
{
    /** Minimum prior non-null points before σ is meaningful. */
    public const MIN_PRIORS = 4;

    /** Maximum trailing window size (2 months of weekly snapshots). */
    public const MAX_WINDOW = 8;

    /** Flat-baseline σ floor in hours (see class docblock). */
    public const SIGMA_FLOOR_HOURS = 0.25;

    /**
     * Detect >2σ anomalies in a nullable-float series.
     *
     * @param  list<float|null>  $values  Chronological (oldest first).
     * @return list<array{
     *     index: int,
     *     value: float,
     *     mean: float,
     *     sigma: float,
     *     sigma_eff: float,
     *     z: float,
     *     direction: 'high'|'low'
     * }>
     */
    public static function detect(array $values): array
    {
        $anomalies = [];

        for ($i = 0, $n = count($values); $i < $n; $i++) {
            $x = $values[$i];

            // Null point — no signal; skip (and don't let it pollute
            // the trailing window of the next point).
            if ($x === null) {
                continue;
            }

            // Build the trailing window: the most recent MAX_WINDOW
            // prior NON-NULL points (look back from i-1).
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

            $sigmaEff = max($sigma, self::SIGMA_FLOOR_HOURS);
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
