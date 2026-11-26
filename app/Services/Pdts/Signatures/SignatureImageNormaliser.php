<?php

namespace App\Services\Pdts\Signatures;

use GdImage;
use RuntimeException;

/**
 * Makes every uploaded signature the same: the signature is found in the
 * picture (even a whole phone photo of the page), cropped close, fitted into
 * exactly 360×140 pixels, and the paper becomes transparent, so it sits
 * cleanly on any document.
 *
 * Ink is told from paper by comparing with the paper's own brightness, so
 * grey shadows and uneven light in a phone photo are not mistaken for ink.
 *
 * Needs PHP's GD extension. Without it, inkSize() and normalise() return null
 * and the upload is kept exactly as it was.
 */
class SignatureImageNormaliser
{
    public const WIDTH = 360;

    public const HEIGHT = 140;

    private const MARGIN = 6;

    /** Photos are reduced to this width first; plenty for a signature. */
    private const WORK_WIDTH = 1200;

    public function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }

    /**
     * The size in pixels of the signature found in the picture, or null when
     * GD is not available.
     *
     * @return array{0: int, 1: int}|null
     */
    public function inkSize(string $bytes): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $image = $this->load($bytes);
        [$left, $top, $right, $bottom] = $this->inkBounds($image, 0);

        return [$right - $left + 1, $bottom - $top + 1];
    }

    /** The standard PNG, or null when GD is not available. */
    public function normalise(string $bytes): ?string
    {
        if (! $this->available()) {
            return null;
        }

        $source = $this->load($bytes);
        [$left, $top, $right, $bottom] = $this->inkBounds($source, 0);
        // Paper and ink levels from around the signature only, not the table or floor.
        [$paper, $limit] = $this->levels($source, [$left, $top, $right, $bottom]);

        $inkWidth = $right - $left + 1;
        $inkHeight = $bottom - $top + 1;
        $scale = min((self::WIDTH - 2 * self::MARGIN) / $inkWidth, (self::HEIGHT - 2 * self::MARGIN) / $inkHeight);
        $width = max(1, (int) round($inkWidth * $scale));
        $height = max(1, (int) round($inkHeight * $scale));

        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 255, 255, 255, 127));
        imagecopyresampled($canvas, $source, (int) ((self::WIDTH - $width) / 2), (int) ((self::HEIGHT - $height) / 2),
            $left, $top, $width, $height, $inkWidth, $inkHeight);

        $this->clearPaper($canvas, $paper, $limit);

        ob_start();
        imagepng($canvas, null, 9);

        return (string) ob_get_clean();
    }

    private function load(string $bytes): GdImage
    {
        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            throw new RuntimeException('The picture could not be read. Save it again as PNG or JPG and try again.');
        }

        if (imagesx($image) > self::WORK_WIDTH) {
            $smaller = imagescale($image, self::WORK_WIDTH, -1, IMG_BICUBIC);
            $image = $smaller;
        }
        imagepalettetotruecolor($image);

        // Transparent areas count as white paper.
        $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $flat;
    }

    /**
     * The paper's brightness, and the brightness below which a pixel is ink:
     * well darker than the paper around it.
     *
     * @return array{0: int, 1: int}
     */
    private function levels(GdImage $image, ?array $area = null): array
    {
        [$x0, $y0, $x1, $y1] = $area ?? [0, 0, imagesx($image) - 1, imagesy($image) - 1];
        $step = max(1, (int) floor(sqrt(($x1 - $x0 + 1) * ($y1 - $y0 + 1) / 40000)));
        $values = [];
        for ($y = $y0; $y <= $y1; $y += $step) {
            for ($x = $x0; $x <= $x1; $x += $step) {
                $values[] = $this->light(imagecolorat($image, $x, $y));
            }
        }
        sort($values);

        // Most of a signature picture is paper: its typical brightness.
        $paper = $values[(int) floor(count($values) * 0.6)] ?? 255;
        $limit = (int) round($paper - max(45, $paper * 0.32));

        return [$paper, max(30, $limit)];
    }

    /**
     * The box around the ink. Each small area of the picture is judged
     * against its own paper: only marks clearly darker than the paper around
     * them count, and only on the paper itself. A dark table or floor around
     * the page, or a shadow, is never taken for ink. Rows and columns with
     * only a speck or two (dust, noise) do not count either.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function inkBounds(GdImage $image, int $limit): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $columns = array_fill(0, $width, 0);
        $rows = array_fill(0, $height, 0);

        [$block, $background, $paperLevel] = $this->localBackground($image);

        // Ink marked on a coarse grid of small cells.
        $cell = 4;
        $gridWidth = (int) ceil($width / $cell);
        $gridHeight = (int) ceil($height / $cell);
        // Clear ink, and faint marks (pencil, a light pen) that count only where they join clear ink.
        $grid = [];
        $faint = [];
        for ($y = 0; $y < $height; $y++) {
            $by = intdiv($y, $block);
            for ($x = 0; $x < $width; $x++) {
                $local = $background[$by][intdiv($x, $block)];
                // Not paper here (a table, the floor, a dark border): no ink to find.
                if ($local < $paperLevel * 0.72) {
                    continue;
                }
                $light = $this->light(imagecolorat($image, $x, $y));
                if ($light < $local - max(40, $local * 0.3)) {
                    $grid[intdiv($y, $cell) * $gridWidth + intdiv($x, $cell)] = true;
                } elseif ($light < $local - max(22, $local * 0.12)) {
                    $faint[intdiv($y, $cell) * $gridWidth + intdiv($x, $cell)] = true;
                }
            }
        }
        $grid = $this->joinFaintInk($grid, $faint, $gridWidth, $gridHeight);

        $box = $this->signatureCells($grid, $gridWidth, $gridHeight);
        if ($box !== null) {
            // Count only the ink inside the signature's own area.
            [$cx0, $cy0, $cx1, $cy1] = $box;
            foreach (array_keys($grid) as $index) {
                $gx = $index % $gridWidth;
                $gy = intdiv($index, $gridWidth);
                if ($gx >= $cx0 && $gx <= $cx1 && $gy >= $cy0 && $gy <= $cy1) {
                    for ($x = $gx * $cell; $x < min($width, ($gx + 1) * $cell); $x++) {
                        $columns[$x] += 2;
                    }
                    for ($y = $gy * $cell; $y < min($height, ($gy + 1) * $cell); $y++) {
                        $rows[$y] += 2;
                    }
                }
            }
        }

        // Ignore the outer few percent: photo edges and shadows of the page border.
        $edgeX = (int) floor($width * 0.02);
        $edgeY = (int) floor($height * 0.02);
        $minColumn = max(2, (int) ceil($height * 0.004));
        $minRow = max(2, (int) ceil($width * 0.004));

        $inkColumns = array_keys(array_filter($columns, fn ($count, $x) => $count >= $minColumn && $x >= $edgeX && $x < $width - $edgeX, ARRAY_FILTER_USE_BOTH));
        $inkRows = array_keys(array_filter($rows, fn ($count, $y) => $count >= $minRow && $y >= $edgeY && $y < $height - $edgeY, ARRAY_FILTER_USE_BOTH));

        if ($inkColumns === [] || $inkRows === []) {
            throw new RuntimeException('No signature could be found in the picture. Sign in dark ink on white paper and try again.');
        }

        $pad = (int) round(max($width, $height) * 0.01);

        return [
            max(0, min($inkColumns) - $pad),
            max(0, min($inkRows) - $pad),
            min($width - 1, max($inkColumns) + $pad),
            min($height - 1, max($inkRows) + $pad),
        ];
    }

    /**
     * Faint cells that connect to clear ink become ink too, so a pencil line
     * stays whole; faint cells on their own (paper grain, a smudge) do not.
     *
     * @param  array<int, true>  $grid  clear ink cells
     * @param  array<int, true>  $faint  faint cells
     * @return array<int, true>
     */
    private function joinFaintInk(array $grid, array $faint, int $gridWidth, int $gridHeight): array
    {
        $queue = array_keys($grid);
        while ($queue) {
            $index = array_pop($queue);
            $x = $index % $gridWidth;
            $y = intdiv($index, $gridWidth);
            for ($dy = -1; $dy <= 1; $dy++) {
                for ($dx = -1; $dx <= 1; $dx++) {
                    $nx = $x + $dx;
                    $ny = $y + $dy;
                    $next = $ny * $gridWidth + $nx;
                    if ($nx >= 0 && $ny >= 0 && $nx < $gridWidth && $ny < $gridHeight && isset($faint[$next]) && ! isset($grid[$next])) {
                        $grid[$next] = true;
                        $queue[] = $next;
                    }
                }
            }
        }

        return $grid;
    }

    /**
     * The signature among the marks found: the largest group of connected
     * strokes, with the smaller pieces close to it (dots, a separate initial).
     * Marks that run into the edge of the photo, such as the edge of the page,
     * a table or a reflection, are not part of a signature.
     *
     * @param  array<int, true>  $grid  ink cells
     * @return array{0: int, 1: int, 2: int, 3: int}|null cell box: left, top, right, bottom
     */
    private function signatureCells(array $grid, int $gridWidth, int $gridHeight): ?array
    {
        $seen = [];
        $groups = [];

        foreach (array_keys($grid) as $start) {
            if (isset($seen[$start])) {
                continue;
            }
            $seen[$start] = true;
            $queue = [$start];
            $size = 0;
            $box = [PHP_INT_MAX, PHP_INT_MAX, -1, -1];
            $touchesEdge = false;

            while ($queue) {
                $index = array_pop($queue);
                $x = $index % $gridWidth;
                $y = intdiv($index, $gridWidth);
                $size++;
                $box = [min($box[0], $x), min($box[1], $y), max($box[2], $x), max($box[3], $y)];
                $touchesEdge = $touchesEdge || $x <= 1 || $y <= 1 || $x >= $gridWidth - 2 || $y >= $gridHeight - 2;

                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $nx = $x + $dx;
                        $ny = $y + $dy;
                        $next = $ny * $gridWidth + $nx;
                        if ($nx >= 0 && $ny >= 0 && $nx < $gridWidth && $ny < $gridHeight && isset($grid[$next]) && ! isset($seen[$next])) {
                            $seen[$next] = true;
                            $queue[] = $next;
                        }
                    }
                }
            }

            if ($size >= 3) {
                $groups[] = ['size' => $size, 'box' => $box, 'edge' => $touchesEdge];
            }
        }

        if ($groups === []) {
            return null;
        }

        // In a photo of a page, marks running into the photo's edge can be the page border or a
        // shadow along it: a thin line down one side, or a frame around the picture. Leave those
        // out. A signature stroke that merely reaches the edge (a long final line, a picture
        // cropped close) is kept.
        $border = function (array $g) use ($gridWidth, $gridHeight): bool {
            if (! $g['edge']) {
                return false;
            }
            [$x0, $y0, $x1, $y1] = $g['box'];
            $sides = ($x0 <= 1) + ($y0 <= 1) + ($x1 >= $gridWidth - 2) + ($y1 >= $gridHeight - 2);
            $thin = ($x1 - $x0 + 1) <= max(2, $gridWidth * 0.04) || ($y1 - $y0 + 1) <= max(2, $gridHeight * 0.04);

            return $thin || $sides >= 3;
        };
        $largest = max(array_column($groups, 'size'));
        $kept = array_values(array_filter($groups, fn ($g) => ! $border($g)));
        if ($kept !== [] && max(array_column($kept, 'size')) >= $largest * 0.2) {
            $groups = $kept;
        }

        usort($groups, fn ($a, $b) => $b['size'] <=> $a['size']);
        $box = array_shift($groups)['box'];

        // Pieces close by belong to the signature too: dots, and other words or initials on the
        // same line ("E. LYAMBO", a first name and a surname). How close is judged against the
        // height of the writing and measured again as pieces join, so a normal gap between
        // words counts, while other writing above or below stays out.
        do {
            $grown = false;
            $height = $box[3] - $box[1] + 1;
            $gap = (int) max(6, round(max($box[2] - $box[0], $height) * 0.15));
            $wordGap = (int) max($gap, round($height * 1.5));
            foreach ($groups as $i => $group) {
                $b = $group['box'];
                $near = $b[0] <= $box[2] + $wordGap && $b[2] >= $box[0] - $wordGap && $b[1] <= $box[3] + $gap && $b[3] >= $box[1] - $gap;
                if ($near) {
                    $box = [min($box[0], $b[0]), min($box[1], $b[1]), max($box[2], $b[2]), max($box[3], $b[3])];
                    unset($groups[$i]);
                    $grown = true;
                }
            }
        } while ($grown);

        return $box;
    }

    /**
     * The picture's background brightness in small squares: in each, the
     * brightness most of it has (the paper, or the table). Thin pen strokes
     * hardly change it. Also the brightness of the paper itself: the
     * brightest backgrounds in the picture.
     *
     * @return array{0: int, 1: list<list<int>>, 2: float} square size, background per square, paper brightness
     */
    private function localBackground(GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $block = max(16, (int) round(max($width, $height) / 40));
        $step = max(1, intdiv($block, 8));
        $background = [];
        $all = [];

        for ($by = 0; $by * $block < $height; $by++) {
            for ($bx = 0; $bx * $block < $width; $bx++) {
                $values = [];
                for ($y = $by * $block; $y < min($height, ($by + 1) * $block); $y += $step) {
                    for ($x = $bx * $block; $x < min($width, ($bx + 1) * $block); $x += $step) {
                        $values[] = $this->light(imagecolorat($image, $x, $y));
                    }
                }
                sort($values);
                $level = $values[(int) floor(count($values) * 0.75)] ?? 255;
                $background[$by][$bx] = $level;
                $all[] = $level;
            }
        }

        // Smooth over the squares' neighbours, so one dense patch of ink does not look like dark background.
        $smoothed = $background;
        foreach ($background as $by => $row) {
            foreach ($row as $bx => $level) {
                $around = [];
                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        if (isset($background[$by + $dy][$bx + $dx])) {
                            $around[] = $background[$by + $dy][$bx + $dx];
                        }
                    }
                }
                $smoothed[$by][$bx] = max($level, (int) round(array_sum($around) / count($around)));
            }
        }

        sort($all);
        $paper = (float) ($all[(int) floor(count($all) * 0.9)] ?? 255);

        return [$block, $smoothed, $paper];
    }

    /**
     * Paper becomes transparent; ink keeps its colour, with soft edges.
     * Faint ink (pencil, a light pen) is judged against its own darkest
     * strokes: those become solid and are darkened, so the signature does not
     * fade away on the document.
     */
    private function clearPaper(GdImage $image, int $paper, int $limit): void
    {
        $soft = max($limit + 1, (int) round(($paper + $limit) / 2));

        $inkLevels = [];
        for ($y = 0; $y < self::HEIGHT; $y++) {
            for ($x = 0; $x < self::WIDTH; $x++) {
                $colour = imagecolorat($image, $x, $y);
                if ((($colour >> 24) & 0x7F) !== 127 && ($light = $this->light($colour)) < $soft) {
                    $inkLevels[] = $light;
                }
            }
        }
        $darken = 1.0;
        if (count($inkLevels) >= 30) {
            sort($inkLevels);
            $darkest = $inkLevels[(int) floor(count($inkLevels) * 0.1)];
            // Faint ink: its pale parts are still ink, not paper.
            if ($darkest > 90) {
                $soft = max($soft, (int) round($paper - max(22, $paper * 0.12)));
            }
            $limit = min($soft - 1, max($limit, (int) round($darkest + ($soft - $darkest) * 0.5)));
            $darken = min(1.0, 80 / max(1, $darkest));
        }

        for ($y = 0; $y < self::HEIGHT; $y++) {
            for ($x = 0; $x < self::WIDTH; $x++) {
                $colour = imagecolorat($image, $x, $y);
                $alpha = ($colour >> 24) & 0x7F;
                if ($alpha === 127) {
                    continue;
                }
                $light = $this->light($colour);
                if ($light >= $soft) {
                    imagesetpixel($image, $x, $y, imagecolorallocatealpha($image, 255, 255, 255, 127));

                    continue;
                }

                // Ink keeps its colour, made darker when it is faint.
                [$r, $g, $b] = array_map(fn ($c) => (int) round($c * $darken),
                    [($colour >> 16) & 0xFF, ($colour >> 8) & 0xFF, $colour & 0xFF]);
                // Pale edges of the strokes fade out instead of leaving a grey fringe.
                $fade = $light > $limit ? (int) round(127 * ($light - $limit) / max(1, $soft - $limit)) : 0;
                imagesetpixel($image, $x, $y, imagecolorallocatealpha($image, $r, $g, $b, max($alpha, $fade)));
            }
        }
    }

    private function light(int $colour): int
    {
        return (int) round(0.299 * (($colour >> 16) & 0xFF) + 0.587 * (($colour >> 8) & 0xFF) + 0.114 * ($colour & 0xFF));
    }
}
