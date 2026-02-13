<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OphanielFunctionsTest extends TestCase {
    public function testParseLlmMetadataJsonParsesFencedJson(): void {
        $raw = "```json\n{\"title\":\"14 Weekly review\",\"summary\":\"Short summary.\"}\n```";
        $parsed = parse_llm_metadata_json($raw);

        $this->assertIsArray($parsed);
        $this->assertSame('14 Weekly review', $parsed['title']);
        $this->assertSame('Short summary.', $parsed['summary']);
    }

    public function testNormalizeTitlePhraseConvertsCamelAndUnderscores(): void {
        $in = 'dailyReview_forHealth-andHabits';
        $out = normalize_title_phrase($in);
        $this->assertSame('daily Review for Health and Habits', $out);
    }

    public function testFormatNoteTitlePrefixesDayAndRemovesExistingPrefix(): void {
        $ts = strtotime('2026-01-22 11:03:00');
        $title = format_note_title('02 weird_oldTitle', $ts);
        $this->assertSame('22 1103 weird old Title', $title);
    }

    public function testDedupeRepetitionCollapsesImmediateRepeats(): void {
        $in = 'I want to show you a monster I want to show you a monster and then continue.';
        $res = dedupe_repetition($in);
        $this->assertIsArray($res);
        $this->assertGreaterThan(0, $res['removed_tokens']);
        $this->assertStringContainsString('and then continue', $res['text']);
    }

    public function testSplitSentencesFallsBackOnWeakPunctuation(): void {
        $in = str_repeat('word ', 85);
        $parts = split_sentences(trim($in));
        $this->assertGreaterThanOrEqual(2, count($parts));
    }

    public function testNormalizeTextProducesParagraphBreaks(): void {
        $in = 'First sentence. Second sentence. Anyway this starts another thought. Fourth sentence.';
        $out = normalize_text($in);
        $this->assertStringContainsString("\n\n", $out);
    }
}
