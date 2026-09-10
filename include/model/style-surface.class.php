<?php

namespace Sassy;

/**
 * A script's style-mutation profile: which markers its text contains, counted, by category.
 *
 * Detection, never interpretation. Regexes over the file, comments and strings included: a
 * false positive costs a glance, a miss costs the signal. The shapes are assignment-shaped where
 * a read would otherwise count, because Sassy's own JS reads rule.style.cssText and
 * document.styleSheets and must report only what phase 6 intended.
 */
class Style_Surface {

    const CATEGORIES = ['scope', 'custom_properties', 'inline_writes', 'layout_reads', 'cssom'];

    const MARKERS = [
        'scope' => [
            'classList'              => '/\.classList\./',
            'dataset'                => '/\.dataset(?:\.|\[)/',
            'data- attribute'        => '/\b(?:set|toggle|remove)Attribute\(\s*[\'"]data-/',
        ],
        'custom_properties' => [
            'setProperty(--)'        => '/setProperty\(\s*[\'"]--/',
        ],
        'inline_writes' => [
            'style.prop ='           => '/\.style\.[A-Za-z]+\s*=(?!=)/',
            'style ='                => '/\.style\s*=(?!=)/',
            'setAttribute(style)'    => '/setAttribute\(\s*[\'"]style[\'"]/',
            'setProperty(prop)'      => '/setProperty\(\s*[\'"](?!--)/',
        ],
        'layout_reads' => [
            'getBoundingClientRect'  => '/getBoundingClientRect/',
            'getComputedStyle'       => '/getComputedStyle/',
            'ResizeObserver'         => '/ResizeObserver/',
            'matchMedia'             => '/matchMedia/',
            'offset/client/scroll'   => '/\.(?:offset|client|scroll)(?:Width|Height|Top|Left)\b/',
        ],
        'cssom' => [
            'adoptedStyleSheets ='   => '/adoptedStyleSheets\s*=(?!=)/',
            'insertRule'             => '/\.insertRule\(/',
            'deleteRule'             => '/\.deleteRule\(/',
            'new CSSStyleSheet'      => '/new\s+CSSStyleSheet/',
            'registerProperty'       => '/CSS\.registerProperty/',
            'createElement(style)'   => '/createElement\(\s*[\'"]style[\'"]/',
        ],
    ];

    /** @var array<string, int> category => count, every category present. */
    public $counts = [];

    /** @var array<string, int> marker => count, markers seen only. */
    public $markers = [];

    /** @var string[] data-* attribute names touched, as written in markup. */
    public $attributes = [];

    /** @var string|null Why there is no profile, or null when there is one. */
    public $reason = null;

    public static function of (Asset $asset) {

        return static::profile([$asset])[$asset->handle];

    }

    /**
     * Profiles a set at once, so the cache is read and written once: 14 MB of script on the
     * reference install, and the record is one transient rather than one per file.
     *
     * @param Asset[] $assets
     * @return static[] Keyed by handle.
     */
    public static function profile (array $assets) {

        $cache   = Compile_Cache::get_surfaces();
        $dirty   = false;
        $results = [];

        foreach ($assets as $asset) {

            $surface = new static();
            $results[$asset->handle] = $surface;

            if ($asset->type !== 'script')                              { $surface->reason = 'not a script'; continue; }
            if (!$asset->is_local())                                    { $surface->reason = 'not a local file'; continue; }
            if (!in_array($asset->extension, ['js', 'mjs'], true))      { $surface->reason = 'not JavaScript'; continue; }

            $path = $asset->get_source_path();

            if (!is_file($path))                                        { $surface->reason = 'missing'; continue; }

            $stamp = Import_Graph::stamp($path);

            if (isset($cache[$path]) && ($cache[$path]['stamp'] ?? null) == $stamp) {
                $surface->load($cache[$path]);
                continue;
            }

            $surface->scan((string) file_get_contents($path));
            $cache[$path] = ['stamp' => $stamp, 'counts' => $surface->counts, 'markers' => $surface->markers, 'attributes' => $surface->attributes];
            $dirty = true;

        }

        if ($dirty) Compile_Cache::set_surfaces($cache);

        return $results;

    }

    public function has_surface () {

        return $this->reason === null;

    }

    /** @return string[] Categories with at least one marker, in canonical order. */
    public function categories () {

        return array_values(array_filter(static::CATEGORIES, function ($category) {
            return ($this->counts[$category] ?? 0) > 0;
        }));

    }

    public function touches ($attribute) {

        $attribute = static::attribute_name($attribute);

        return in_array($attribute, $this->attributes, true);

    }

    /** `scope 4, layout reads 2` for the row formats. */
    public function summary () {

        if ($this->reason !== null) return '(' . $this->reason . ')';

        $parts = [];
        foreach ($this->categories() as $category) $parts[] = str_replace('_', ' ', $category) . ' ' . $this->counts[$category];

        return $parts ? implode(', ', $parts) : '(none)';

    }

    protected function load (array $record) {

        $this->counts     = $record['counts'] ?? [];
        $this->markers    = $record['markers'] ?? [];
        $this->attributes = $record['attributes'] ?? [];

    }

    protected function scan ($text) {

        foreach (static::MARKERS as $category => $markers) {
            $this->counts[$category] = 0;
            foreach ($markers as $name => $pattern) {
                $n = preg_match_all($pattern, $text);
                if ($n) { $this->markers[$name] = $n; $this->counts[$category] += $n; }
            }
        }

        $names = [];

        if (preg_match_all('/\b(?:set|toggle|remove)Attribute\(\s*[\'"](data-[a-z0-9_-]+)/i', $text, $m)) foreach ($m[1] as $name) $names[] = strtolower($name);
        if (preg_match_all('/\.dataset\.([A-Za-z_$][\w$]*)/', $text, $m))                                  foreach ($m[1] as $name) $names[] = static::attribute_name($name);
        if (preg_match_all('/\.dataset\[\s*[\'"]([A-Za-z_$][\w$]*)/', $text, $m))                          foreach ($m[1] as $name) $names[] = static::attribute_name($name);

        $this->attributes = array_values(array_unique($names));

    }

    /**
     * The attribute as written in markup: dataset.fooBar is data-foo-bar by the DOM's own rule,
     * and a name given without the prefix gains it.
     */
    public static function attribute_name ($name) {

        $name = (string) $name;

        if (str_starts_with($name, 'data-')) return strtolower($name);

        return 'data-' . strtolower(preg_replace('/([A-Z])/', '-$1', $name));

    }

}
