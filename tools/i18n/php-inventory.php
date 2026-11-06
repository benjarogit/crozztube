<?php

// Static source parsing only: no app bootstrap, controller calls or database.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

$root = dirname(__DIR__, 2);
$parser = (new ParserFactory)->createForNewestSupportedVersion();
$finder = new NodeFinder;
$messages = [];
$add = static function (String_ $node, string $file, string $kind) use (&$messages, $root): void {
    $text = $node->value;
    if (! preg_match('/[a-zA-Z]/', $text) || preg_match('/^[a-z0-9_.:\/{}-]+$/D', $text) || preg_match('/^https?:\/\//', $text)) return;
    $messages[$text] ??= ['message' => $text, 'locations' => []];
    $messages[$text]['locations'][] = ['file' => substr($file, strlen($root) + 1), 'line' => $node->getStartLine(), 'kind' => $kind];
};
$directories = [$root.'/app', $root.'/docker/plugin-src'];
foreach ($directories as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') continue;
        $filename = $file->getPathname();
        $ast = $parser->parse(file_get_contents($filename));
        $visit = static function (Node $node) use ($filename, $finder, $add): void {
            if ($node instanceof Node\Expr\ArrayItem && $node->key instanceof String_ && in_array($node->key->value, ['message', 'label', 'title', 'subtitle', 'description', 'help', 'subject', 'body', 'navigation_label', 'placeholder'], true)) {
                foreach ($finder->findInstanceOf([$node->value], String_::class) as $string) $add($string, $filename, 'php-label');
            }
            if ($node instanceof Node\Expr\Throw_ || $node instanceof Node\Stmt\Throw_) {
                foreach ($finder->findInstanceOf([$node], String_::class) as $string) $add($string, $filename, 'php-error');
            }
            if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && in_array(strtolower($node->name->toString()), ['abort', 'abort_if', 'abort_unless'], true)) {
                $index = strtolower($node->name->toString()) === 'abort' ? 1 : 2;
                if (($node->args[$index]->value ?? null) instanceof String_) $add($node->args[$index]->value, $filename, 'php-error');
            }
        };
        foreach ($finder->find($ast, static fn (Node $node): bool => true) as $node) $visit($node);
        // Default help/FAQ/page definitions contain nested paragraph arrays.
        if (str_ends_with($filename, '/ContentPages.php')) {
            foreach ($finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
                if (! in_array($method->name->toString(), ['definitions', 'definitionFromSections'], true)) continue;
                foreach ($finder->findInstanceOf($method->stmts ?? [], String_::class) as $string) $add($string, $filename, 'php-default-page');
            }
        }
    }
}
echo json_encode(array_values($messages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
