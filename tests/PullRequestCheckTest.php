<?php

declare(strict_types=1);

namespace Fedale\PackageCatalog\Tests;

use Fedale\PackageCatalog\BaseBranch;
use Fedale\PackageCatalog\ChangedFile;
use Fedale\PackageCatalog\Owners\Maintainers;
use Fedale\PackageCatalog\PullRequestCheck;
use Fedale\PackageCatalog\Tests\Support\PublicMembers;
use Fedale\PackageCatalog\Tests\Support\TemporaryCatalog;
use Fedale\PackageCatalog\Vendors;
use Fedale\PackageCatalog\Yanked;
use Fedale\PackageFormat\DocumentCodec;
use Fedale\PackageFormat\FieldTypes\FieldTypeCatalog;
use Fedale\PackageFormat\PackageChecker;
use Fedale\PackageFormat\PackageDirectory;
use Fedale\PackageFormat\SchemaValidator;
use Fedale\PackageFormat\SeedRecords;
use Fedale\PackageFormat\Versions\ItemStates;
use PHPUnit\Framework\TestCase;

final class PullRequestCheckTest extends TestCase
{
    use TemporaryCatalog;

    /** @var list<string> the version directories the base branch has */
    private array $published = [];

    /** yanked.yaml on the base branch */
    private string $yanked = '';

    protected function setUp(): void
    {
        $this->createCatalog();
    }

    protected function tearDown(): void
    {
        $this->removeCatalog();
    }

    public function testAcceptsANewVersionByAnOwner(): void
    {
        $this->published[] = $this->addVersion('1.0.0');
        $added = $this->addVersion('1.1.0');

        self::assertSame([], $this->check($this->added($added), author: 'Mario-Rossi'));
        // Run locally by a maintainer: no author, no ownership check.
        self::assertSame([], $this->check($this->added($added), author: null));
    }

    public function testANewVersionIsWrittenInFormatV2(): void
    {
        // A version published in v1 stays as it is; the next one is v2.
        $this->published[] = $this->addVersionV1('1.0.0');

        self::assertSame([], $this->check($this->added($this->addVersion('1.1.0'))));
        self::assertContains(
            'packages/acme/shop/1.2.0: A new version is written in format v2: its manifest is package.yaml, of kind Package (metadata:package:export writes it so).',
            $this->check($this->added($this->addVersionV1('1.2.0'))),
        );
    }

    public function testAcceptsTheFirstVersionsOfAPackageTogether(): void
    {
        $changes = [...$this->added($this->addVersion('1.0.0')), ...$this->added($this->addVersion('1.1.0'))];

        self::assertSame([], $this->check($changes));
    }

    public function testIgnoresWhatIsNotAPackage(): void
    {
        self::assertSame([], $this->check([new ChangedFile('M', 'vendors.yaml'), new ChangedFile('A', 'README.md')]));
    }

    public function testAPublishedVersionNeverChanges(): void
    {
        $this->published[] = $this->addVersion('1.0.0');

        self::assertSame([
            'packages/acme/shop/1.0.0/objects/tag.yaml: A published version never changes: publish a new version instead.',
            'packages/acme/shop/1.0.0/files/products/oslo.png: A published version never changes: publish a new version instead.',
        ], $this->check([
            new ChangedFile('M', 'packages/acme/shop/1.0.0/objects/tag.yaml'),
            new ChangedFile('D', 'packages/acme/shop/1.0.0/files/products/oslo.png'),
        ]));
        // Adding a file to a published version changes it too.
        self::assertSame(
            ['packages/acme/shop/1.0.0/README.md: A published version never changes: publish a new version instead.'],
            $this->check([new ChangedFile('A', 'packages/acme/shop/1.0.0/README.md')]),
        );
    }

    public function testAFileOutsideAVersionDirectory(): void
    {
        self::assertSame(
            ['packages/acme/shop/README.md: A package\'s files go in packages/<vendor>/<name>/<version>/.'],
            $this->check([new ChangedFile('A', 'packages/acme/shop/README.md')]),
        );
    }

    public function testTheAuthorMustOwnTheVendor(): void
    {
        $globex = $this->addVersion('1.0.0', 'globex/shop');
        $acme = $this->addVersion('1.0.0');

        self::assertSame([
            'packages/acme/shop/1.0.0: anna is not an owner of the vendor "acme" (vendors.yaml).',
            'packages/globex/shop/1.0.0: The vendor "globex" is not registered: add it to vendors.yaml in a pull request of its own first.',
        ], array_values(array_filter(
            $this->check([...$this->added($globex), ...$this->added($acme)], author: 'anna'),
            // globex/shop's documents keep the acme ids of the example: only the vendor problems matter here.
            static fn (string $problem): bool => str_ends_with($problem, '(vendors.yaml).') || str_ends_with($problem, 'first.'),
        )));
    }

    public function testTheNewVersionMustBeHigher(): void
    {
        $this->published[] = $this->addVersion('1.0.0');
        $this->published[] = $this->addVersion('1.2.0');

        self::assertSame(
            ['packages/acme/shop/1.1.0: acme/shop 1.2.0 is already listed: the new version must be higher.'],
            $this->check($this->added($this->addVersion('1.1.0'))),
        );
    }

    /**
     * The number must fit what the version changed against the highest one listed, as
     * metadata-driven requires when it upgrades.
     */
    public function testTheNumberMustFitTheChanges(): void
    {
        $this->published[] = $this->addVersion('1.0.0');
        $colour = "\n    - id: acme.tag.colour\n      name: colour\n      type: text\n      label: Colour\n";

        $patch = $this->addVersion('1.0.1');
        file_put_contents($this->root.'/'.$patch.'/objects/tag.yaml', $colour, \FILE_APPEND);
        self::assertSame(
            ['packages/acme/shop/1.0.1/objects/tag.yaml: field acme.tag.colour: an addition needs a new minor version, 1.0.1 is not one.'],
            $this->check($this->added($patch)),
        );

        $minor = $this->addVersion('1.1.0');
        file_put_contents($this->root.'/'.$minor.'/objects/tag.yaml', $colour, \FILE_APPEND);
        self::assertSame([], $this->check($this->added($minor)));
    }

    public function testARenameNeedsAMajorVersion(): void
    {
        $this->published[] = $this->addVersion('1.0.0');
        $this->published[] = $this->addVersion('1.1.0');
        $rename = static fn (string $directory, string $root): int|false => file_put_contents(
            $root.'/'.$directory.'/objects/tag.yaml',
            str_replace("  plural: Tags\n", "  plural: Labels\n", str_replace('  name: tag', '  name: label', (string) file_get_contents($root.'/'.$directory.'/objects/tag.yaml'))),
        );

        $minor = $this->addVersion('1.2.0');
        $rename($minor, $this->root);
        // Compared with 1.1.0, the highest listed.
        self::assertSame(
            ['packages/acme/shop/1.2.0/objects/tag.yaml: object acme.tag: a rename needs a new major version, 1.2.0 is not one.'],
            $this->check($this->added($minor)),
        );

        $major = $this->addVersion('2.0.0');
        $rename($major, $this->root);
        self::assertSame([], $this->check($this->added($major)));
    }

    public function testAnOwnerRemovesAPackage(): void
    {
        $this->published[] = $this->addVersion('1.0.0');
        file_put_contents($this->root.'/yanked.yaml', "acme/shop: all\n");

        self::assertSame([], $this->check([new ChangedFile('M', 'yanked.yaml')]));
        self::assertSame(
            ['yanked.yaml: anna is not an owner of the vendor "acme": only its owners remove its packages.'],
            $this->check([new ChangedFile('M', 'yanked.yaml')], author: 'anna'),
        );

        file_put_contents($this->root.'/yanked.yaml', "acme/other: all\n");
        self::assertSame(['yanked.yaml: acme/other is not published.'], $this->check([new ChangedFile('M', 'yanked.yaml')]));
    }

    public function testARemovedPackageTakesNoNewVersion(): void
    {
        $this->published[] = $this->addVersion('1.0.0');
        $this->yanked = "acme/shop: all\n";

        self::assertSame(
            ['packages/acme/shop/1.1.0: acme/shop is removed (yanked.yaml: all): list it again before publishing a new version.'],
            $this->check($this->added($this->addVersion('1.1.0'))),
        );
    }

    public function testAPublicMemberOfAnOwningOrganizationPublishes(): void
    {
        $added = $this->added($this->addVersion('1.0.0'));
        $vendors = "acme:\n  owners: [mario-rossi, acme-corp]\n";
        $members = new PublicMembers(['acme-corp' => ['anna']]);

        self::assertSame([], $this->check($added, author: 'anna', members: $members, vendors: $vendors));
        // A listed owner needs no question to GitHub.
        $listed = new PublicMembers();
        self::assertSame([], $this->check($added, author: 'mario-rossi', members: $listed, vendors: $vendors));
        self::assertSame([], $listed->asked);

        self::assertSame(
            ['packages/acme/shop/1.0.0: bruno is not an owner of the vendor "acme" (vendors.yaml).'],
            $this->check($added, author: 'bruno', members: $members, vendors: $vendors),
        );
    }

    public function testNoGuessWhenGitHubCannotBeAsked(): void
    {
        self::assertSame(
            ['packages/acme/shop/1.0.0: GitHub answered 503 when asked whether anna is a public member of acme-corp. Run the check again.'],
            $this->check($this->added($this->addVersion('1.0.0')), author: 'anna', members: new PublicMembers(unreachable: true), vendors: "acme:\n  owners: [acme-corp]\n"),
        );
    }

    public function testOnlyAMaintainerVerifiesAVendor(): void
    {
        file_put_contents($this->root.'/vendors.yaml', "acme:\n  owners: [mario-rossi]\n  verified: true\n");
        $changes = [new ChangedFile('M', 'vendors.yaml')];

        self::assertSame(
            ['vendors.yaml: acme: only the maintainers (.github/CODEOWNERS) verify a vendor, or stop verifying it.'],
            $this->check($changes, author: 'mario-rossi'),
        );
        self::assertSame([], $this->check($changes, author: 'Fedale'));
        // Stopping: the same rule.
        file_put_contents($this->root.'/vendors.yaml', "acme:\n  owners: [mario-rossi]\n");
        self::assertSame(
            ['vendors.yaml: acme: only the maintainers (.github/CODEOWNERS) verify a vendor, or stop verifying it.'],
            $this->check($changes, author: 'mario-rossi', vendors: "acme:\n  owners: [mario-rossi]\n  verified: true\n"),
        );
    }

    public function testVendorsYamlMustStayValid(): void
    {
        file_put_contents($this->root.'/vendors.yaml', "acme:\n  owners: [mario-rossi]\n  verified: yes please\n");

        self::assertSame(['vendors.yaml: acme: verified is true or false.'], $this->check([new ChangedFile('M', 'vendors.yaml')]));
    }

    public function testTheManifestMustMatchItsDirectory(): void
    {
        $this->published[] = $this->addVersion('1.0.0');

        self::assertSame(
            ['packages/acme/shop/1.1.0: The manifest says acme/shop 1.0.0: the directory must be packages/acme/shop/1.0.0/.'],
            $this->check($this->added($this->addVersion('1.1.0', manifestVersion: '1.0.0'))),
        );
    }

    public function testNamesAndVersionsFollowTheFormat(): void
    {
        self::assertSame(
            ['packages/acme/Shop/1.0.0: "acme/Shop" is not a package name (vendor/name, lowercase letters, digits and hyphens).'],
            $this->check([new ChangedFile('A', 'packages/acme/Shop/1.0.0/package.yaml')]),
        );
        self::assertSame(
            ['packages/acme/shop/v1: "v1" is not a version (major.minor.patch, e.g. 1.0.0).'],
            $this->check([new ChangedFile('A', 'packages/acme/shop/v1/package.yaml')]),
        );
    }

    public function testReportsTheProblemsOfThePackageWhereTheyAre(): void
    {
        $added = $this->addVersion('1.0.0');
        file_put_contents($this->root.'/'.$added.'/objects/tag.yaml', str_replace('plural:', 'plurals:', (string) file_get_contents($this->root.'/'.$added.'/objects/tag.yaml')));

        $problems = $this->check($this->added($added));

        self::assertNotSame([], array_filter($problems, static fn (string $problem): bool => str_starts_with($problem, 'packages/acme/shop/1.0.0/objects/tag.yaml: spec')));
        foreach ($problems as $problem) {
            self::assertStringStartsWith('packages/acme/shop/1.0.0/', $problem);
        }
    }

    public function testAMissingManifest(): void
    {
        mkdir($this->root.'/packages/acme/shop/1.0.0', 0o777, true);
        file_put_contents($this->root.'/packages/acme/shop/1.0.0/README.md', '# Shop');

        self::assertSame(
            ['packages/acme/shop/1.0.0/package.yaml: No manifest in "'.$this->root.'/packages/acme/shop/1.0.0".'],
            $this->check([new ChangedFile('A', 'packages/acme/shop/1.0.0/README.md')]),
        );
    }

    public function testReadsNothingThroughASymbolicLink(): void
    {
        $added = $this->addVersion('1.0.0');
        // A manifest pointing at a file of the runner: read, it would show up in a problem.
        rename($this->root.'/'.$added.'/package.yaml', $this->root.'/manifest.yaml');
        symlink($this->root.'/manifest.yaml', $this->root.'/'.$added.'/package.yaml');
        file_put_contents($this->root.'/manifest.yaml', 'runner secret: [');

        $problems = $this->check($this->added($added));

        self::assertSame(['packages/acme/shop/1.0.0/package.yaml: Symbolic links are not allowed.'], $problems);
    }

    public function testRefusesALinkedDirectoryAboveTheVersion(): void
    {
        $this->addVersion('1.0.0', 'globex/shop');
        symlink($this->root.'/packages/globex', $this->root.'/packages/acme');

        self::assertSame(
            ['packages/acme: Not a directory: symbolic links are not allowed.'],
            $this->check([new ChangedFile('A', 'packages/acme/shop/1.0.0/package.yaml')]),
        );
    }

    public function testRefusesAHeavyVersion(): void
    {
        $added = $this->addVersion('1.0.0');
        // A sparse file: 21 MB as far as its size goes, nothing on the disk.
        $heavy = fopen($this->root.'/'.$added.'/files/catalogue.pdf', 'w');
        self::assertIsResource($heavy);
        ftruncate($heavy, 21_000_000);
        fclose($heavy);

        self::assertContains('packages/acme/shop/1.0.0: 21.0 MB: a version may weigh 20 MB at most, files included.', $this->check($this->added($added)));
    }

    public function testAnOwnerYanksAPublishedVersion(): void
    {
        $this->published[] = $this->addVersion('1.0.0');
        file_put_contents($this->root.'/yanked.yaml', "acme/shop: [1.0.0]\n");

        self::assertSame([], $this->check([new ChangedFile('M', 'yanked.yaml')]));
        self::assertSame(
            ['yanked.yaml: anna is not an owner of the vendor "acme": only its owners yank its versions.'],
            $this->check([new ChangedFile('M', 'yanked.yaml')], author: 'anna'),
        );
    }

    public function testListingAVersionAgainIsAnOwnersCallToo(): void
    {
        $this->published[] = $this->addVersion('1.0.0');
        $this->yanked = "acme/shop: [1.0.0]\n";
        file_put_contents($this->root.'/yanked.yaml', "# nothing yanked\n");

        self::assertSame([], $this->check([new ChangedFile('M', 'yanked.yaml')]));
        self::assertSame(
            ['yanked.yaml: anna is not an owner of the vendor "acme": only its owners yank its versions.'],
            $this->check([new ChangedFile('M', 'yanked.yaml')], author: 'anna'),
        );
    }

    public function testYanksPublishedVersionsOnly(): void
    {
        $this->published[] = $this->addVersion('1.0.0');
        file_put_contents($this->root.'/yanked.yaml', "acme/shop: [1.0.0, 1.1.0]\n");

        self::assertSame(['yanked.yaml: acme/shop 1.1.0 is not published.'], $this->check([new ChangedFile('M', 'yanked.yaml')]));

        file_put_contents($this->root.'/yanked.yaml', "acme/shop: 1.0.0\n");
        self::assertSame(
            ['yanked.yaml: "acme/shop" is not a package name followed by a list of versions, or by "all".'],
            $this->check([new ChangedFile('M', 'yanked.yaml')]),
        );
    }

    public function testChecksAVersionAgainstThePackagesItRequires(): void
    {
        $this->published[] = $this->addFixture('places', '1.2.0');

        // Its relations and its cascade name the region and the province of acme/places.
        self::assertSame([], $this->check($this->added($this->addFixture('people', '1.0.0'))));
    }

    public function testTakesTheHighestPublishedVersionTheConstraintAllows(): void
    {
        $this->published[] = $this->addFixture('places', '1.2.0');
        // 2.0.0 drops the province's region, which acme/people's cascade names: ^1.0 leaves it out.
        $this->published[] = $places = $this->addFixture('places', '2.0.0');
        $province = $this->root.'/'.$places.'/objects/province.yaml';
        file_put_contents($province, (string) preg_replace('/    - id: acme\.province\.region\n(      .*\n)+/', '', (string) file_get_contents($province)));

        self::assertSame([], $this->check($this->added($this->addFixture('people', '1.0.0'))));
        self::assertNotSame([], $this->check($this->added($this->addFixture('people', '1.1.0', ['acme/places' => '^2.0']))));
    }

    public function testRefusesARequirementTheCatalogDoesNotPublish(): void
    {
        $problem = 'packages/acme/people/1.0.0/package.yaml: spec.requires.acme/places: The catalog publishes no version of acme/places that fits ^1.0 and is not yanked: publish it first, in a pull request of its own.';
        $people = $this->added($this->addFixture('people', '1.0.0'));

        // Nor in the same pull request: it is not published yet.
        self::assertContains($problem, $this->check([...$people, ...$this->added($this->addFixture('places', '1.2.0'))]));
        // Not said twice, the checker's way.
        self::assertSame([], array_values(array_filter($this->check($people), static fn (string $given): bool => str_contains($given, 'must be installed'))));

        $this->published[] = 'packages/acme/places/1.2.0';
        $this->yanked = "acme/places: [1.2.0]\n";
        self::assertContains($problem, $this->check($people));
    }

    public function testRefusesPackagesRequiringEachOther(): void
    {
        $this->published[] = $this->addFixture('places', '1.2.0');
        $this->published[] = $this->addFixture('people', '1.0.0');

        self::assertContains(
            'packages/acme/places/1.3.0/package.yaml: spec.requires: These packages would require each other: acme/places requires acme/people requires acme/places. None of them could be installed.',
            $this->check($this->added($this->addFixture('places', '1.3.0', ['acme/people' => '^1.0']))),
        );
    }

    /**
     * @param list<ChangedFile> $changes
     *
     * @return list<string>
     */
    private function check(array $changes, ?string $author = 'mario-rossi', ?PublicMembers $members = null, string $vendors = "acme:\n  owners: [mario-rossi]\n"): array
    {
        $check = new PullRequestCheck(
            new PackageDirectory(new DocumentCodec()),
            new PackageChecker(new SchemaValidator(), new FieldTypeCatalog(), new SeedRecords()),
            new ItemStates(new FieldTypeCatalog()),
            $members ?? new PublicMembers(),
        );

        return $check->check($this->root, $changes, new BaseBranch(Vendors::fromYaml($vendors), $this->published, Yanked::fromYaml($this->yanked), Maintainers::fromCodeowners("* @fedale\n")), $author);
    }

    /**
     * @return list<ChangedFile>
     */
    private function added(string $directory): array
    {
        return array_map(static fn (string $path): ChangedFile => new ChangedFile('A', $path), $this->filesOf($directory));
    }
}
