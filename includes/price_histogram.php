<?php
// Bucket boundaries for the price distribution chart on stats.php.
//
// Fixed boundaries (5, 10, 20, 50) only fit currencies where a subscription
// costs a few units: in JPY, HUF or INR nearly everything lands in "50+".
// The boundaries are derived from the prices instead. Sturges' rule picks the
// number of buckets, the width covers prices up to the 90th percentile (so one
// expensive outlier doesn't squash everything into the first bucket), and the
// width is rounded up to 1, 2, 2.5 or 5 times a power of ten so labels stay
// readable. Anything above the last boundary goes into the open "N+" bucket.

/**
 * Round a positive width up to 1, 2, 2.5 or 5 times a power of ten.
 */
function wallos_histogram_nice_width($width)
{
    if (!is_finite($width) || $width <= 0) {
        return 1.0;
    }

    $magnitude = pow(10, floor(log10($width)));
    foreach ([1, 2, 2.5, 5, 10] as $step) {
        $candidate = $step * $magnitude;
        // Tolerance so 20 / 2 doesn't round up to 20 through float error
        if ($candidate >= $width * (1 - 1e-9)) {
            return (float) $candidate;
        }
    }

    return (float) (10 * $magnitude);
}

/**
 * Decimal places that keep boundaries a given width apart distinct, at
 * least 2 so ordinary prices still read like money.
 */
function wallos_histogram_decimals($width)
{
    return max(2, (int) ceil(-log10($width)) + 1);
}

/**
 * @param float[] $prices monthly prices in the main currency
 * @return float[]
 */
function wallos_histogram_prices(array $prices)
{
    return array_values(array_filter(array_map('floatval', $prices), fn($price) => $price >= 0));
}

/**
 * @param float[] $prices monthly prices in the main currency
 * @return float[] ascending upper bounds of every bucket but the open last one
 */
function wallos_price_histogram_boundaries(array $prices)
{
    $prices = wallos_histogram_prices($prices);
    sort($prices);
    $count = count($prices);

    if ($count === 0) {
        return [];
    }

    // Sturges' rule, kept between 3 and 8 buckets for a readable chart
    $buckets = max(3, min(8, (int) ceil(log($count, 2)) + 1));

    $top = $prices[(int) floor(0.9 * ($count - 1))];
    if ($top <= 0) {
        $top = $prices[$count - 1];
    }

    // Spread the top over every bucket, so the open last one starts just
    // below it and holds the most expensive tenth or so
    $width = wallos_histogram_nice_width($top / $buckets);

    // Sub-cent widths (crypto main currencies) keep enough decimals to stay apart
    $decimals = wallos_histogram_decimals($width);
    $boundaries = [];
    for ($index = 1; $index < $buckets; $index++) {
        $boundaries[] = round($width * $index, $decimals);
    }

    return $boundaries;
}

/**
 * @param float[] $prices monthly prices in the main currency
 * @return array<int, array{label: string, y: int}>
 */
function wallos_price_histogram_data_points(array $prices)
{
    $prices = wallos_histogram_prices($prices);
    $boundaries = wallos_price_histogram_boundaries($prices);
    if ($boundaries === []) {
        return [];
    }

    $counts = array_fill(0, count($boundaries) + 1, 0);
    foreach ($prices as $price) {
        $bucketIndex = count($boundaries);
        foreach ($boundaries as $index => $boundary) {
            if ($price < $boundary) {
                $bucketIndex = $index;
                break;
            }
        }
        $counts[$bucketIndex]++;
    }

    $decimals = wallos_histogram_decimals($boundaries[0]);
    $format = function ($value) use ($decimals) {
        return rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
    };

    $dataPoints = [];
    $previousBoundary = 0;
    foreach ($boundaries as $index => $boundary) {
        $dataPoints[] = ['label' => $format($previousBoundary) . '–' . $format($boundary), 'y' => $counts[$index]];
        $previousBoundary = $boundary;
    }
    $dataPoints[] = ['label' => $format($previousBoundary) . '+', 'y' => $counts[count($boundaries)]];

    return $dataPoints;
}
