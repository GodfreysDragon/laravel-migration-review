<?php

namespace MigrationReview\Support;

use MigrationReview\Support\Parsed\BlueprintCall;
use MigrationReview\Support\Parsed\CodeCall;
use MigrationReview\Support\Parsed\ParsedMigration;
use MigrationReview\Support\Parsed\SchemaOperation;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Turns a migration file into a structured description of what its up() method does.
 *
 * This is pure static analysis: the migration is never executed, so it works without a
 * database and cannot have side effects.
 */
class MigrationParser
{
    private const QUERY_WRITE_METHODS = ['update', 'delete', 'insert', 'insertOrIgnore', 'upsert', 'updateOrInsert', 'truncate', 'increment', 'decrement', 'insertUsing'];

    private const RAW_SQL_METHODS = ['statement', 'unprepared', 'update', 'delete', 'insert', 'affectingStatement'];

    private Parser $parser;

    private NodeFinder $finder;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->finder = new NodeFinder;
    }

    public function parse(MigrationFile $file): ParsedMigration
    {
        try {
            $ast = $this->parser->parse($file->contents) ?? [];
        } catch (Error $e) {
            return new ParsedMigration($file, [], [], false, null, null, $e->getMessage());
        }

        $ast = (new NodeTraverser(new NameResolver))->traverse($ast);

        /** @var Class_|null $class */
        $class = $this->finder->findFirstInstanceOf($ast, Class_::class);
        $up = $class?->getMethod('up');
        $down = $class?->getMethod('down');
        $upStatements = $up->stmts ?? [];

        return new ParsedMigration(
            file: $file,
            operations: $this->schemaOperations($upStatements),
            codeCalls: $this->codeCalls($upStatements),
            hasUp: $up !== null,
            downStatementCount: $down === null ? null : count($down->stmts ?? []),
            classLine: $class?->getStartLine(),
        );
    }

    /**
     * @param  array<Node>  $statements
     * @return list<SchemaOperation>
     */
    private function schemaOperations(array $statements): array
    {
        $operations = [];

        foreach ($this->finder->find($statements, fn (Node $node) => $this->isSchemaCall($node)) as $call) {
            if (! $call instanceof StaticCall && ! $call instanceof MethodCall) {
                continue;
            }

            $args = array_map(fn (Node\Arg $arg) => $this->literal($arg->value), array_values(array_filter($call->args, fn ($a) => $a instanceof Node\Arg)));

            $operations[] = new SchemaOperation(
                method: $call->name instanceof Node\Identifier ? $call->name->toString() : '?',
                table: is_string($args[0] ?? null) ? $args[0] : null,
                args: $args,
                line: $call->getStartLine(),
                blueprintCalls: $this->blueprintCalls($call),
            );
        }

        usort($operations, fn (SchemaOperation $a, SchemaOperation $b) => $a->line <=> $b->line);

        return $operations;
    }

    private function isSchemaCall(Node $node): bool
    {
        if ($node instanceof StaticCall) {
            return $this->isClass($node->class, 'Schema')
                && $node->name instanceof Node\Identifier
                && $node->name->toString() !== 'connection';
        }

        // Schema::connection('pgsql')->table(...)
        return $node instanceof MethodCall
            && $node->var instanceof StaticCall
            && $this->isClass($node->var->class, 'Schema');
    }

    /**
     * @return list<BlueprintCall>
     */
    private function blueprintCalls(StaticCall|MethodCall $call): array
    {
        $callback = null;

        foreach ($call->args as $arg) {
            if ($arg instanceof Node\Arg && ($arg->value instanceof Expr\Closure || $arg->value instanceof Expr\ArrowFunction)) {
                $callback = $arg->value;
                break;
            }
        }

        if ($callback === null || ! isset($callback->params[0]) || ! $callback->params[0]->var instanceof Expr\Variable) {
            return [];
        }

        $blueprintVar = $callback->params[0]->var->name;
        $body = $callback instanceof Expr\ArrowFunction ? [$callback->expr] : $callback->stmts;

        $chains = array_filter($this->finder->findInstanceOf($body, MethodCall::class), fn (MethodCall $node) => $this->rootVariable($node) === $blueprintVar);

        // Keep only the outermost call of each fluent chain.
        $inner = [];
        foreach ($chains as $chain) {
            if ($chain->var instanceof MethodCall) {
                $inner[spl_object_id($chain->var)] = true;
            }
        }

        $calls = [];
        foreach ($chains as $chain) {
            if (! isset($inner[spl_object_id($chain)])) {
                $calls[] = new BlueprintCall($this->flatten($chain), $chain->getStartLine());
            }
        }

        usort($calls, fn (BlueprintCall $a, BlueprintCall $b) => $a->line <=> $b->line);

        return $calls;
    }

    /**
     * @return list<array{method: string, args: list<mixed>}>
     */
    private function flatten(MethodCall $call): array
    {
        $links = [];
        $node = $call;

        while ($node instanceof MethodCall) {
            array_unshift($links, [
                'method' => $node->name instanceof Node\Identifier ? $node->name->toString() : '?',
                'args' => array_values(array_map(fn ($arg) => $arg instanceof Node\Arg ? $this->literal($arg->value) : null, $node->args)),
            ]);
            $node = $node->var;
        }

        return $links;
    }

    private function rootVariable(Expr $expr): ?string
    {
        while ($expr instanceof MethodCall) {
            $expr = $expr->var;
        }

        return $expr instanceof Expr\Variable && is_string($expr->name) ? $expr->name : null;
    }

    /**
     * @param  array<Node>  $statements
     * @return list<CodeCall>
     */
    private function codeCalls(array $statements): array
    {
        $calls = [];

        foreach ($this->finder->findInstanceOf($statements, Expr\CallLike::class) as $node) {
            if (! $node instanceof StaticCall && ! $node instanceof MethodCall) {
                continue;
            }

            $method = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

            if ($method === null) {
                continue;
            }

            if ($node instanceof StaticCall && $this->isClass($node->class, 'DB') && in_array($method, self::RAW_SQL_METHODS, true)) {
                $sql = isset($node->args[0]) && $node->args[0] instanceof Node\Arg ? $this->literal($node->args[0]->value) : null;
                $calls[] = new CodeCall(CodeCall::RAW_SQL, 'DB::'.$method, $node->getStartLine(), sql: is_string($sql) ? $sql : null);
            } elseif ($node instanceof MethodCall && in_array($method, self::QUERY_WRITE_METHODS, true) && ($table = $this->queryBuilderTable($node)) !== false) {
                $calls[] = new CodeCall(CodeCall::QUERY_WRITE, ($table ?? 'query').'->'.$method, $node->getStartLine(), table: $table);
            } elseif ($node instanceof StaticCall && $node->class instanceof Name && $this->looksLikeModel($node->class)) {
                $calls[] = new CodeCall(CodeCall::MODEL, $node->class->toString().'::'.$method, $node->getStartLine());
            }
        }

        usort($calls, fn (CodeCall $a, CodeCall $b) => $a->line <=> $b->line);

        return $calls;
    }

    /**
     * The table of a DB::table('x')->...->update() chain; null if the table is dynamic, false if not a DB::table chain.
     */
    private function queryBuilderTable(MethodCall $call): string|null|false
    {
        $node = $call->var;

        while ($node instanceof MethodCall) {
            $node = $node->var;
        }

        if ($node instanceof StaticCall && $this->isClass($node->class, 'DB')
            && $node->name instanceof Node\Identifier && $node->name->toString() === 'table') {
            $table = isset($node->args[0]) && $node->args[0] instanceof Node\Arg ? $this->literal($node->args[0]->value) : null;

            return is_string($table) ? $table : null;
        }

        return false;
    }

    private function looksLikeModel(Name $name): bool
    {
        $class = $name->toString();

        return str_contains($class, '\\Models\\') || str_starts_with($class, 'App\\');
    }

    private function isClass(Node $class, string $shortName): bool
    {
        return $class instanceof Name && $class->getLast() === $shortName;
    }

    /**
     * Evaluate a literal expression (strings, numbers, booleans, arrays of those); anything dynamic becomes null.
     */
    private function literal(Expr $expr): mixed
    {
        return match (true) {
            $expr instanceof Scalar\String_ => $expr->value,
            $expr instanceof Scalar\Int_, $expr instanceof Scalar\Float_ => $expr->value,
            $expr instanceof Expr\ConstFetch => match (strtolower($expr->name->toString())) {
                'true' => true,
                'false' => false,
                default => null,
            },
            $expr instanceof Expr\Array_ => array_map(fn (Node\ArrayItem $item) => $this->literal($item->value), $expr->items),
            default => null,
        };
    }
}
