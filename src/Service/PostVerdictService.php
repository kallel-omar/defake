<?php

namespace App\Service;

class PostVerdictService
{
    public function calculate(array $claimResults): array
    {
       $total = count($claimResults);

if ($total === 0) {
    return [
        'score' => 0,
        'verdict' => 'NOT_VERIFIABLE',
        'explanation' => 'No verifiable claims were found.',
    ];
}

$scores = array_column($claimResults, 'score');
$averageScore = (int) round(array_sum($scores) / $total);
$explanation = sprintf(
    'The final verdict is based on %d extracted claim(s), with an average verification score of %d/100.',
    $total,
    $averageScore
);
return [
    'score' => $averageScore,
    'verdict' => 'Unknown',
    'explanation' => $explanation,
];}
}