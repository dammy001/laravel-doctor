<?php

namespace Bunce\LaravelDoctor\Scanners\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\While_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Throwable;

final class AstEngine
{
    /** @var array<string, list<Node>> */
    private static array $astCache = [];

    /**
     * @return array<int, array{line:int, code:string}>
     */
    public function findPotentialLoopQueries(string $file): array
    {
        $stmts = $this->parseFile($file);
        if ($stmts === []) {
            return [];
        }

        $finder = new NodeFinder;
        $loops = $finder->find($stmts, static function (Node $node): bool {
            return $node instanceof Foreach_
                || $node instanceof For_
                || $node instanceof While_
                || $node instanceof Do_;
        });
        $results = [];
        $seen = [];

        foreach ($loops as $loop) {
            if (! $loop instanceof Node) {
                continue;
            }

            $calls = $finder->find($loop, function (Node $node): bool {
                return $this->isPotentialQueryCall($node);
            });

            foreach ($calls as $call) {
                $line = (int) $call->getStartLine();
                $key = (string) $line;
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $results[] = [
                    'line' => $line,
                    'code' => $this->compactPrintNode($call),
                ];
            }
        }

        return $results;
    }

    /**
     * @return list<Node>
     */
    private function parseFile(string $file): array
    {
        if (isset(self::$astCache[$file])) {
            return self::$astCache[$file];
        }

        if (! class_exists(ParserFactory::class)) {
            self::$astCache[$file] = [];

            return [];
        }

        $code = file_get_contents($file);
        if (! is_string($code) || $code === '') {
            self::$astCache[$file] = [];

            return [];
        }

        try {
            $parser = (new ParserFactory)->createForHostVersion();
            $parsed = $parser->parse($code);
            self::$astCache[$file] = is_array($parsed) ? $parsed : [];
        } catch (Throwable) {
            self::$astCache[$file] = [];
        }

        return self::$astCache[$file];
    }

    private function isPotentialQueryCall(Node $node): bool
    {
        if ($node instanceof StaticCall) {
            $class = $node->class instanceof Name ? $node->class->toString() : null;
            $method = $node->name instanceof Node\Identifier ? strtolower($node->name->toString()) : null;
            if (! is_string($class) || ! is_string($method)) {
                return false;
            }

            if ($class === 'DB' && in_array($method, ['table', 'connection'], true)) {
                return true;
            }

            if (in_array($method, ['query', 'where', 'all', 'find', 'findorfail', 'first'], true)) {
                return true;
            }
        }

        if (! $node instanceof MethodCall) {
            return false;
        }

        $method = $node->name instanceof Node\Identifier ? strtolower($node->name->toString()) : null;
        if (! is_string($method)) {
            return false;
        }

        return in_array($method, ['get', 'first', 'paginate', 'count', 'exists', 'chunk', 'cursor', 'lazy'], true);
    }

    private function compactPrintNode(Node $node): string
    {
        return sprintf('%s at line %d', $node->getType(), (int) $node->getStartLine());
    }
}
