<?php
declare(strict_types=1);

use TribuneDesk\Protocol\BodyFigures;

/**
 * Plain PHP: BodyFigures has no WordPress in it, so this test needs none either.
 */
class Test_Body_Figures extends \PHPUnit\Framework\TestCase
{
    public function test_reads_a_toolbar_figure(): void
    {
        $html = '<p>a</p><figure class="wp-block-image alignleft size-large is-resized" style="width:44%" data-hf-caption="x">'
            . '<img src="https://s.example/a.jpg?x=1&amp;y=2" alt="Ksiądz" data-td-asset-id="11111111-1111-1111-1111-111111111111" data-td-cms-media-id="77" style="aspect-ratio:4/3">'
            . '<figcaption class="wp-element-caption"> Podpis <b>x</b> </figcaption></figure><p>b</p>';

        $found = BodyFigures::find($html);

        $this->assertCount(1, $found);
        $this->assertSame('https://s.example/a.jpg?x=1&y=2', $found[0]['src']);
        $this->assertSame('Ksiądz', $found[0]['alt']);
        $this->assertSame('Podpis <b>x</b>', $found[0]['caption']);
        $this->assertSame('left', $found[0]['align']);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $found[0]['asset_id']);
        $this->assertSame('77', $found[0]['cms_media_id']);
        $this->assertSame('<figure', substr($html, $found[0]['start'], 7));
        $this->assertSame('</figure>', substr($html, $found[0]['end'] - 9, 9));
    }

    public function test_hidden_caption_and_wide_figure(): void
    {
        $found = BodyFigures::find('<figure class="alignwide" data-hf-caption="Ukryty"><img src="a.jpg"></figure>');

        $this->assertSame('', $found[0]['caption']);
        $this->assertSame('none', $found[0]['align']);
        $this->assertSame('', $found[0]['asset_id']);
    }

    public function test_align_right_and_center(): void
    {
        $this->assertSame('right', BodyFigures::find('<figure class="x alignright"><img src="a"></figure>')[0]['align']);
        $this->assertSame('center', BodyFigures::find('<figure class="aligncenter"><img src="a"></figure>')[0]['align']);
        $this->assertSame('none', BodyFigures::find('<figure class="notalignleft"><img src="a"></figure>')[0]['align']);
    }

    public function test_figures_without_exactly_one_img_are_not_returned(): void
    {
        $html = '<figure><iframe src="y"></iframe></figure><figure><img src="a"><img src="b"></figure><figure></figure>';

        $this->assertSame([], BodyFigures::find($html));
    }

    public function test_greater_than_inside_a_quoted_attribute_does_not_end_the_tag(): void
    {
        $html = '<figure><img alt="a > b" src="https://s.example/a.jpg" data-td-asset-id=\'22222222-2222-2222-2222-222222222222\'></figure>';

        $found = BodyFigures::find($html);

        $this->assertCount(1, $found);
        $this->assertSame('a > b', $found[0]['alt']);
        $this->assertSame('https://s.example/a.jpg', $found[0]['src']);
        $this->assertSame('22222222-2222-2222-2222-222222222222', $found[0]['asset_id']);
    }

    public function test_attribute_text_inside_another_value_is_not_an_attribute(): void
    {
        $found = BodyFigures::find('<figure><img alt="x data-td-asset-id=\'evil\'" src="a"></figure>');

        $this->assertSame('', $found[0]['asset_id']);
    }

    public function test_img_text_inside_an_attribute_does_not_count_as_a_second_img(): void
    {
        $found = BodyFigures::find('<figure><img src="a" alt="<img src=b>"></figure>');

        $this->assertCount(1, $found);
    }

    public function test_replace_keeps_everything_else_byte_identical(): void
    {
        $html = "<p>A  &nbsp; [x]</p>\n<figure><img src=\"1\"></figure>\n<figure><iframe></iframe></figure>\n<figure><img src=\"2\"></figure><br>";

        $out = BodyFigures::replace($html, static fn (array $f): ?string => $f['src'] === '2' ? '[IMG]' : null);

        $this->assertSame("<p>A  &nbsp; [x]</p>\n<figure><img src=\"1\"></figure>\n<figure><iframe></iframe></figure>\n[IMG]<br>", $out);
    }

    public function test_replace_with_null_everywhere_returns_the_same_string(): void
    {
        $html = '<figure><img src="1"></figure> tail';

        $this->assertSame($html, BodyFigures::replace($html, static fn (array $f): ?string => null));
    }

    public function test_unclosed_figure_is_left_alone(): void
    {
        $this->assertSame([], BodyFigures::find('<figure><img src="a">'));
    }
}
