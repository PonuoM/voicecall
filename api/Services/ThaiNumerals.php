<?php

/**
 * Converts spoken Thai digit sequences into the digits they stand for, before a language model is
 * asked to read them.
 *
 * Typhoon transcribes numbers as words — "ศูนย์เก้าสามสามหนึ่งห้าสามแปดสามศูนย์" for 0933153830 —
 * and until now the analysis model was left to turn that back into digits. On the first real call
 * through the new pipeline it returned 093-315-8305: the right digits, two of them swapped. That is
 * not a rare stumble to prompt around, it is the wrong tool for the job. Reading ten words in order
 * is a lookup, and a lookup should not be probabilistic.
 *
 * The stakes are concrete: ErpLookupService matches customers to the ERP by phone number, so a
 * single transposed digit means no match, which then shows up as a ghost number, an unlinked order,
 * and a fraud check with nothing behind it.
 *
 * Scope is deliberately narrow. Only runs of consecutive digit words are converted — long enough to
 * be a spoken-out number rather than an ordinary count. "ห้ากระสอบ" (five sacks) and "ห้าสิบ" (fifty)
 * are left alone: they are compositional Thai numerals, not digit sequences, and the model handles
 * them correctly. Widening this to cardinals would mean rewriting "สามพันเจ็ดร้อย" and risking real
 * text for a problem that does not exist.
 */
class ThaiNumerals
{
    /**
     * Digit words as spoken when reading a number out one figure at a time.
     *
     * Ordered longest-first because the alternation is matched against unspaced text: Typhoon emits
     * no word boundaries, so "สี่" must be tried before "สี" would ever match a prefix of it.
     */
    private const DIGITS = [
        'ศูนย์' => '0',
        'หนึ่ง' => '1',
        'เอ็ด' => '1',   // "เอ็ด" is how 1 is said in final position (ยี่สิบเอ็ด), and turns up mid-sequence too
        'สอง' => '2',
        'สาม' => '3',
        'สี่' => '4',
        'ห้า' => '5',
        'หก' => '6',
        'เจ็ด' => '7',
        'แปด' => '8',
        'เก้า' => '9',
        'โอ' => '0',     // English "oh" for zero, common when reading phone numbers aloud
    ];

    /**
     * Words the recogniser produces in place of a digit word, accepted only when they appear inside
     * a run that already looks like a spoken number.
     *
     * "ส่วน" is the one that mattered: Typhoon wrote "เบอร์โทรศัพท์ส่วนเก้าสามสาม…" where the speaker
     * said "ศูนย์". On its own "ส่วน" means part or portion and must never be read as a zero — which
     * is why these are only consulted between two confirmed digit words, never at the edges of a run.
     */
    private const CONFUSIONS = [
        'ส่วน' => '0',
        'สูญ' => '0',
        'สาย' => '3',
    ];

    /** Shortest run treated as a spoken-out number rather than ordinary counting. */
    private const MIN_RUN = 4;

    /**
     * @return string The text with spoken digit runs replaced by their digits.
     */
    public static function normalize(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $tokens = self::tokenPattern();
        // A run is MIN_RUN or more digit words back to back, optionally with confusable words
        // between them — the leading and trailing token must be an unambiguous digit.
        $pattern = '/(?:' . $tokens . '){' . self::MIN_RUN . ',}/u';

        return (string) preg_replace_callback($pattern, function (array $m) {
            return self::runToDigits($m[0]);
        }, $text);
    }

    /**
     * Every spoken digit run in the text, as digit strings. Useful for handing a model an
     * authoritative list rather than trusting it to spot them.
     *
     * @return string[]
     */
    public static function extractSequences(string $text): array
    {
        $pattern = '/(?:' . self::tokenPattern() . '){' . self::MIN_RUN . ',}/u';
        preg_match_all($pattern, $text, $matches);

        $found = [];
        foreach ($matches[0] as $run) {
            $digits = self::runToDigits($run);
            if ($digits !== '' && ctype_digit($digits)) {
                $found[] = $digits;
            }
        }
        return array_values(array_unique($found));
    }

    private static function tokenPattern(): string
    {
        $words = array_merge(array_keys(self::DIGITS), array_keys(self::CONFUSIONS));
        // Longest first so a shorter word can never claim the prefix of a longer one.
        usort($words, function ($a, $b) {
            return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
        });
        return implode('|', array_map('preg_quote', $words));
    }

    private static function runToDigits(string $run): string
    {
        $map = self::DIGITS + self::CONFUSIONS;
        preg_match_all('/' . self::tokenPattern() . '/u', $run, $matches);
        $words = $matches[0];

        // The safety test is how much of the run is unambiguous, not where the ambiguous words sit.
        // An earlier version refused to read a confusable word in first position, which threw away
        // the leading zero of the very phone number this class exists for: Typhoon wrote
        // "เบอร์โทรศัพท์ส่วนเก้าสาม…" where the speaker said "ศูนย์". Nine confirmed digits after it
        // leave no real doubt.
        //
        // Requiring MIN_RUN confirmed digits is what keeps ordinary prose out. "อัตราส่วนหนึ่งต่อหนึ่ง"
        // reaches "ส่วน" with a single digit word beside it and is left exactly as written.
        $confirmed = 0;
        foreach ($words as $word) {
            if (isset(self::DIGITS[$word])) {
                $confirmed++;
            }
        }
        if ($confirmed < self::MIN_RUN) {
            return $run;
        }

        return self::wordsToDigits($words, $map);
    }

    /**
     * @param string[] $words
     * @param array<string,string> $map
     */
    private static function wordsToDigits(array $words, array $map): string
    {
        $digits = '';
        foreach ($words as $word) {
            $digits .= $map[$word] ?? '';
        }
        return $digits;
    }
}
