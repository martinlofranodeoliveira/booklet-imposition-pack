<?php
declare(strict_types=1);
namespace Lofrano\Booklet;

final class Imposer
{
    // No runtime failure-injection option. Regression tests set this private field by reflection.
    private bool $failRenderer = false;

    /**
     * @param list<int|array{page:int,label?:?string}> $orderedOneBasedPages
     * @param array{width:int|float,height:int|float,back_rotation?:int} $sheetGeometry
     * @param array{root:string,name:string} $safeOwnedOutputOptions
     * @return array{directory:string,map:array,validation:array}
     */
    public function impose(string $inputPdf, array $orderedOneBasedPages, int $signatureSize, array $sheetGeometry, array $safeOwnedOutputOptions): array
    {
        $deadline = hrtime(true) + 20000000000;
        $selection = Selection::parse($orderedOneBasedPages);
        $geometry = Geometry::parse($sheetGeometry);
        $map = Planner::plan($selection, $signatureSize, $geometry);
        Failure::need(count($safeOwnedOutputOptions) === 2 && is_string($safeOwnedOutputOptions['root'] ?? null) && is_string($safeOwnedOutputOptions['name'] ?? null), 'output_options');
        $store = new OwnedStore($safeOwnedOutputOptions['root'], $deadline);
        $name = $safeOwnedOutputOptions['name'];
        $store->available($name);
        $source = OwnedStore::local($inputPdf);
        Failure::need(!str_starts_with($source, $store->root . '/'), 'source_namespace');
        $stage = $store->stage();
        try {
            $hash = $store->copySource($source, $stage, $deadline);
            $runner = new Runner($stage, $deadline);
            $count = Inspector::inspect($runner->run('inspect-source'));
            foreach ($selection as $occurrence) {
                Failure::need($occurrence['page'] <= $count, 'selection_page_missing');
            }
            $runner->run('normalize');
            clearstatcache();
            Failure::need(is_file($stage . '/work/normalized.pdf') && filesize($stage . '/work/normalized.pdf') > 0 && filesize($stage . '/work/normalized.pdf') <= 67108864, 'normalized_size');
            Failure::need(Inspector::inspect($runner->run('inspect-normalized')) === $count, 'source_page_count');
            $map['source_pages'] = $count;
            $map['source_sha256'] = $hash;
            $map['pdf'] = 'booklet.pdf';
            Failure::write($stage . '/work/job.json', Failure::json(['map' => $map]));
            $runner->run('worker');
            Failure::need(is_file($stage . '/page-map.json') && filesize($stage . '/page-map.json') <= 131072, 'map_validation');
            $map = json_decode((string) file_get_contents($stage . '/page-map.json'), true, 64, JSON_THROW_ON_ERROR);
            $report = Validator::validate($stage, $runner, $map, $this->failRenderer);
            Failure::time($deadline);
            Failure::need(hash_file('sha256', $source) === $hash, 'source_changed');
            $directory = $store->publish($stage, $name, $deadline);
            return ['directory' => $directory, 'map' => $map, 'validation' => $report];
        } catch (Failure $error) {
            throw $error;
        } catch (\Throwable) {
            throw new Failure('workflow_failed');
        } finally {
            unset($store);
        }
    }

    public function recover(string $root): int
    {
        $store = new OwnedStore($root, hrtime(true) + 20000000000);
        return $store->recover();
    }
}
