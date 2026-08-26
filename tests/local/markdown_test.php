<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Tests for the chat Markdown renderer.
 *
 * @package    block_openaiagent
 * @copyright  2025 RSMAX Consulting SL <julio@rsmax.es>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_openaiagent\local;

/**
 * Unit tests for Markdown to HTML conversion of assistant replies.
 *
 * @covers \block_openaiagent\local\markdown
 */
final class markdown_test extends \advanced_testcase {
    /**
     * A list written straight after its lead-in line still renders as a list.
     *
     * Classic Markdown only opens a list after a blank line, and models
     * routinely omit it, so the reply used to render as one run-on paragraph
     * with literal hyphens in it.
     */
    public function test_list_without_blank_line_still_renders_as_a_list(): void {
        $this->resetAfterTest();

        $html = markdown::to_html(
            "Cómo funciona (resumen según la guía):\n"
                . "- Paso 1: ¿Qué debería haber ocurrido?\n"
                . "- Paso 2: ¿Qué ocurrió realmente?",
            \context_system::instance()
        );

        $this->assertStringContainsString('<ul>', $html);
        $this->assertSame(2, substr_count($html, '<li>'));
        $this->assertStringContainsString('Cómo funciona', $html);
    }

    /**
     * Numbered lists get the same treatment.
     */
    public function test_numbered_list_without_blank_line(): void {
        $this->resetAfterTest();

        $html = markdown::to_html(
            "Pasos:\n1. Definir la meta.\n2. Explorar la realidad.",
            \context_system::instance()
        );

        $this->assertStringContainsString('<ol>', $html);
        $this->assertSame(2, substr_count($html, '<li>'));
    }

    /**
     * Markdown that was already correct is not disturbed.
     */
    public function test_well_formed_list_is_unchanged(): void {
        $this->resetAfterTest();

        $html = markdown::to_html(
            "Según la guía:\n\n- Comenzar con un comentario positivo.\n- Describir el hecho.",
            \context_system::instance()
        );

        $this->assertStringContainsString('<ul>', $html);
        $this->assertSame(2, substr_count($html, '<li>'));
    }

    /**
     * A hyphen used mid-sentence must not be mistaken for a list.
     */
    public function test_hyphen_inside_a_sentence_is_not_a_list(): void {
        $this->resetAfterTest();

        $html = markdown::to_html(
            "El plan es a corto plazo - conviene revisarlo.\nY después seguimos.",
            \context_system::instance()
        );

        $this->assertStringNotContainsString('<ul>', $html);
        $this->assertStringNotContainsString('<li>', $html);
    }
}
