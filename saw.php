<?php
/**
 * Simple Additive Weighting (SAW) calculation helpers.
 *
 * Input values come from vendor_criteria_scores.raw_value and active criteria
 * from evaluation_criteria. Only vendors with verification_status = 'verified'
 * are eligible; vendors missing any active criterion are excluded so an
 * incomplete alternative cannot distort the normalization extrema.
 */

/**
 * Normalize a vendor value using the SAW cost/benefit formula.
 *
 * Cost: min(value) / vendor value.
 * Benefit: vendor value / max(value).
 */
function calculateNormalization(float $vendorValue, float $referenceValue, string $criterionType): float
{
    if ($criterionType === 'cost') {
        if ($vendorValue <= 0 || $referenceValue <= 0) {
            throw new InvalidArgumentException('Nilai cost dan nilai minimum harus lebih besar dari nol.');
        }

        return $referenceValue / $vendorValue;
    }

    if ($criterionType === 'benefit') {
        if ($referenceValue <= 0) {
            throw new InvalidArgumentException('Nilai maksimum benefit harus lebih besar dari nol.');
        }

        return $vendorValue / $referenceValue;
    }

    throw new InvalidArgumentException('Jenis kriteria SAW harus cost atau benefit.');
}

/**
 * Sum the weighted normalized values into one final SAW score.
 * Each input item is expected to contain a numeric weighted_value field.
 */
function calculateFinalScore(array $criterionScores): float
{
    $finalScore = 0.0;
    foreach ($criterionScores as $criterionScore) {
        if (!isset($criterionScore['weighted_value']) || !is_numeric($criterionScore['weighted_value'])) {
            throw new InvalidArgumentException('Setiap kriteria harus memiliki nilai weighted_value numerik.');
        }

        $finalScore += (float)$criterionScore['weighted_value'];
    }

    return $finalScore;
}

/**
 * Calculate each complete verified vendor's normalization, weighted values,
 * final score, and ranking using the criteria and scores stored in the DB.
 *
 * @return array<int, array<string, mixed>> Sorted highest score first. Each
 *         vendor has vendor_id, vendor_name, final_score, ranking, and criteria.
 */
function calculateVendorScores(mysqli $conn): array
{
    $criteriaResult = $conn->query(
        "SELECT id, criterion_code, name, criterion_type, weight
         FROM evaluation_criteria
         WHERE is_active = 1
         ORDER BY criterion_code ASC"
    );
    if (!$criteriaResult) {
        throw new RuntimeException('Gagal membaca kriteria SAW: ' . $conn->error);
    }

    $criteria = [];
    $weightTotal = 0.0;
    while ($criterion = $criteriaResult->fetch_assoc()) {
        $criterion['id'] = (int)$criterion['id'];
        $criterion['weight'] = (float)$criterion['weight'];
        $criterion['criterion_type'] = strtolower((string)$criterion['criterion_type']);
        if (!in_array($criterion['criterion_type'], ['cost', 'benefit'], true)) {
            throw new RuntimeException('Jenis kriteria tidak valid: ' . $criterion['criterion_code']);
        }

        $criteria[] = $criterion;
        $weightTotal += $criterion['weight'];
    }
    $criteriaResult->free();

    if ($criteria === []) {
        return [];
    }

    // Enforce the configured SAW requirement that active weights total 1.00.
    if (abs($weightTotal - 1.0) > 0.00005) {
        throw new RuntimeException(sprintf('Total bobot kriteria aktif harus 1.00; total saat ini %.4f.', $weightTotal));
    }

    $verifiedVendorsResult = $conn->query(
        "SELECT id, name
         FROM vendors
         WHERE verification_status = 'verified'
         ORDER BY id ASC"
    );
    if (!$verifiedVendorsResult) {
        throw new RuntimeException('Gagal membaca vendor terverifikasi: ' . $conn->error);
    }

    $vendors = [];
    while ($vendor = $verifiedVendorsResult->fetch_assoc()) {
        $vendorId = (int)$vendor['id'];
        $vendors[$vendorId] = [
            'vendor_id' => $vendorId,
            'vendor_name' => (string)$vendor['name'],
            'raw_values' => [],
        ];
    }
    $verifiedVendorsResult->free();

    if ($vendors === []) {
        return [];
    }

    $scoresResult = $conn->query(
        "SELECT vcs.vendor_id, vcs.criterion_id, vcs.raw_value
         FROM vendor_criteria_scores AS vcs
         INNER JOIN vendors AS v ON v.id = vcs.vendor_id
         INNER JOIN evaluation_criteria AS ec ON ec.id = vcs.criterion_id
         WHERE v.verification_status = 'verified'
           AND ec.is_active = 1
           AND ec.criterion_code <> 'C1'"
    );
    if (!$scoresResult) {
        throw new RuntimeException('Gagal membaca nilai vendor: ' . $conn->error);
    }

    $activeCriterionIds = [];
    foreach ($criteria as $criterion) {
        $activeCriterionIds[$criterion['id']] = true;
    }

    while ($score = $scoresResult->fetch_assoc()) {
        $vendorId = (int)$score['vendor_id'];
        $criterionId = (int)$score['criterion_id'];
        $rawValue = (float)$score['raw_value'];

        if (isset($vendors[$vendorId], $activeCriterionIds[$criterionId])) {
            $vendors[$vendorId]['raw_values'][$criterionId] = $rawValue;
        }
    }
    $scoresResult->free();

    // C1 is derived from actual stored license prices, not a vendor-entered score.
    // Annual prices are converted to monthly equivalent; lifetime offers are
    // not comparable on this cost basis and therefore do not supply C1.
    $priceResult = $conn->query(
        "SELECT lp.vendor_id,
                MIN(CASE
                    WHEN LOWER(TRIM(lp.billing_cycle)) IN ('annual', 'tahunan') THEN lp.price_per_user / 12
                    WHEN LOWER(TRIM(lp.billing_cycle)) IN ('monthly', 'bulanan') THEN lp.price_per_user
                    ELSE NULL
                END) AS monthly_price_per_user
         FROM license_prices AS lp
         INNER JOIN vendors AS v ON v.id = lp.vendor_id
         WHERE v.verification_status = 'verified'
         GROUP BY lp.vendor_id"
    );
    if (!$priceResult) {
        throw new RuntimeException('Gagal membaca harga lisensi vendor: ' . $conn->error);
    }

    $costCriterionIds = [];
    foreach ($criteria as $criterion) {
        if ($criterion['criterion_type'] === 'cost' && $criterion['criterion_code'] === 'C1') {
            $costCriterionIds[] = $criterion['id'];
        }
    }
    while ($price = $priceResult->fetch_assoc()) {
        $vendorId = (int)$price['vendor_id'];
        if (!isset($vendors[$vendorId]) || $price['monthly_price_per_user'] === null) {
            continue;
        }
        foreach ($costCriterionIds as $criterionId) {
            $vendors[$vendorId]['raw_values'][$criterionId] = (float)$price['monthly_price_per_user'];
        }
    }
    $priceResult->free();

    // Exclude incomplete or invalid vendors before finding criterion min/max.
    // This ensures excluded alternatives cannot alter other vendors' scores.
    $eligibleVendors = [];
    foreach ($vendors as $vendor) {
        $complete = true;
        foreach ($criteria as $criterion) {
            $criterionId = $criterion['id'];
            if (!array_key_exists($criterionId, $vendor['raw_values'])) {
                $complete = false;
                break;
            }

            $value = $vendor['raw_values'][$criterionId];
            if (!is_finite($value) || $value <= 0
                || ($criterion['criterion_type'] === 'benefit' && ($value < 1 || $value > 5))
                || ($criterion['criterion_type'] === 'cost' && $value <= 0)) {
                $complete = false;
                break;
            }
        }

        if ($complete) {
            $eligibleVendors[$vendor['vendor_id']] = $vendor;
        }
    }

    if ($eligibleVendors === []) {
        return [];
    }

    // Determine normalization extrema only from complete verified alternatives.
    $extrema = [];
    foreach ($criteria as $criterion) {
        $values = [];
        foreach ($eligibleVendors as $vendor) {
            $values[] = $vendor['raw_values'][$criterion['id']];
        }
        $extrema[$criterion['id']] = $criterion['criterion_type'] === 'cost'
            ? min($values)
            : max($values);
    }

    $rankedVendors = [];
    foreach ($eligibleVendors as $vendor) {
        $criterionScores = [];
        foreach ($criteria as $criterion) {
            $criterionId = $criterion['id'];
            $rawValue = $vendor['raw_values'][$criterionId];
            $normalizedValue = calculateNormalization(
                $rawValue,
                $extrema[$criterionId],
                $criterion['criterion_type']
            );
            $weightedValue = $normalizedValue * $criterion['weight'];

            $criterionScores[$criterion['criterion_code']] = [
                'criterion_id' => $criterionId,
                'criterion_code' => (string)$criterion['criterion_code'],
                'name' => (string)$criterion['name'],
                'type' => $criterion['criterion_type'],
                'weight' => $criterion['weight'],
                'raw_value' => $rawValue,
                'normalized_value' => $normalizedValue,
                'weighted_value' => $weightedValue,
            ];
        }

        $rankedVendors[] = [
            'vendor_id' => $vendor['vendor_id'],
            'vendor_name' => $vendor['vendor_name'],
            'final_score' => calculateFinalScore($criterionScores),
            'ranking' => 0,
            'criteria' => $criterionScores,
        ];
    }

    // Highest final score is rank 1. Vendor ID breaks exact score ties consistently.
    usort($rankedVendors, static function (array $left, array $right): int {
        $scoreOrder = $right['final_score'] <=> $left['final_score'];
        return $scoreOrder !== 0 ? $scoreOrder : ($left['vendor_id'] <=> $right['vendor_id']);
    });

    foreach ($rankedVendors as $index => &$vendor) {
        $vendor['ranking'] = $index + 1;
    }
    unset($vendor);

    return $rankedVendors;
}

/**
 * Return all ranked vendors, or the single ranking record for a vendor ID.
 * Returns null when the vendor is not eligible for the current SAW ranking.
 *
 * @return array<int, array<string, mixed>>|array<string, mixed>|null
 */
function getVendorRanking(mysqli $conn, ?int $vendorId = null): array|null
{
    $ranking = calculateVendorScores($conn);
    if ($vendorId === null) {
        return $ranking;
    }

    foreach ($ranking as $vendor) {
        if ($vendor['vendor_id'] === $vendorId) {
            return $vendor;
        }
    }

    return null;
}
