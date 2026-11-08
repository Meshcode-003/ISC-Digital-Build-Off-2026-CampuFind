<?php

/**
 * SMART MATCH ASSISTANT
 * Compares the original lost-item report with an ownership claim.
 */

function getWords($text) {

    // Convert text to lowercase
    $text = strtolower($text);

    // Remove punctuation and special characters
    $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

    // Split text into individual words
    $words = preg_split('/\s+/', trim($text));

    // Words that do not contribute much to matching
    $stopWords = [
        'the', 'a', 'an', 'and', 'or', 'is', 'it',
        'was', 'were', 'my', 'i', 'me', 'with',
        'has', 'have', 'had', 'this', 'that',
        'of', 'in', 'on', 'at', 'to', 'for',
        'its', 'very', 'there', 'their', 'from'
    ];

    // Remove unnecessary words
    $words = array_filter($words, function($word) use ($stopWords) {

        return strlen($word) > 1 &&
               !in_array($word, $stopWords, true);

    });

    // Remove duplicate words
    return array_values(array_unique($words));

}


/**
 * Compare the original item with the ownership claim.
 */
function analyzeClaim($item, $claim) {

    // Original lost-item information
    $originalDescription =
        ($item['item_name'] ?? '') . ' ' .
        ($item['description'] ?? '');

    // Claimant information
    $claimDescription =
        ($claim['claim_description'] ?? '') . ' ' .
        ($claim['ownership_evidence'] ?? '');


    // Convert both descriptions into word collections
    $originalWords = getWords($originalDescription);

    $claimWords = getWords($claimDescription);


    // Prevent division by zero
    if (count($originalWords) === 0 || count($claimWords) === 0) {

        return [
            'score' => 0,
            'recommendation' => 'weak_match',
            'explanation' => 'There is insufficient description information to compare.'
        ];

    }


    // Find words appearing in both descriptions
    $matchingWords = array_intersect(
        $originalWords,
        $claimWords
    );


    $matchCount = count($matchingWords);

    $originalCount = count($originalWords);

    $claimCount = count($claimWords);


    // Calculate Dice similarity percentage
    $score = (
        (2 * $matchCount) /
        ($originalCount + $claimCount)
    ) * 100;


    $score = round($score, 2);


    // Generate recommendation
    if ($score >= 65) {

        $recommendation = 'strong_match';

        $explanation =
            'Strong similarity detected. Several words and identifying details match. Administrator should verify ownership.';

    } elseif ($score >= 35) {

        $recommendation = 'possible_match';

        $explanation =
            'Some similarities were detected. Further investigation is recommended.';

    } else {

        $recommendation = 'weak_match';

        $explanation =
            'Few similarities were detected. Administrator should carefully examine the claim and supporting evidence.';

    }


    // Return the analysis
    return [

        'score' => $score,

        'recommendation' => $recommendation,

        'explanation' => $explanation,

        'matching_words' => array_values($matchingWords)

    ];

}

?>
