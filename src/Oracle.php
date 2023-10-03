<?php

namespace BeyondCode\Oracle;

use BeyondCode\Oracle\Exceptions\PotentiallyUnsafeQuery;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenAI\Client;

class Oracle
{
    protected string $connection;

    public function __construct(protected Client $client)
    {
        $this->connection = config('ask-database.connection');
    }

    public function ask(string $question): array
    {
        $query = $this->getQuery($question);

        $result = json_encode($this->evaluateQuery($query));

        $prompt = $this->buildPrompt($question, $query, $result);

        $answer = $this->queryOpenAi($prompt, "\n", 0.7);

        return [
            'query' => $query,
            'result' => $result,
            'prompt' => $prompt,
            'answer' => Str::of($answer)->trim()->trim('"'),
        ];
    }

    public function getQuery(string $question): string
    {
        $prompt = $this->buildPrompt($question);

        $query = $this->queryOpenAi($prompt, "\n");
        $splittedQuery = Str::of($query)->split("/\n/");
        $query = Str::of(
                $splittedQuery
                ->take($splittedQuery->count() - 2)
                ->join(' ')
            )
            ->trim()
            ->trim('"');

        $this->ensureQueryIsSafe($query);

        return $query;
    }

    protected function queryOpenAi(string $prompt, string $stop, float $temperature = 0.0)
    {
        $completions = $this->client->completions()->create([
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
        ]);

        return $completions->choices[0]->text;
    }

    protected function buildPrompt(string $question, string $query = null, string $result = null): string
    {
        $tables = $this->getTables($question);

        $prompt = (string) view('ask-database::prompts.query', [
            'question' => $question,
            'tables' => $tables,
            'dialect' => $this->getDialect(),
            'query' => $query,
            'result' => $result,
        ]);

        return rtrim($prompt, PHP_EOL);
    }

    protected function evaluateQuery(string $query): object|array
    {
        return DB::connection($this->connection)->select($this->getRawQuery($query)) ?? new \stdClass();
    }

    protected function getRawQuery(string $query): string
    {
        if (version_compare(app()->version(), '10.0', '<')) {
            /* @phpstan-ignore-next-line */
            return (string) DB::raw($query);
        }

        return DB::raw($query)->getValue(DB::connection($this->connection)->getQueryGrammar());
    }

    /**
     * @throws PotentiallyUnsafeQuery
     */
    protected function ensureQueryIsSafe(string $query): void
    {
        if (!config('ask-database.strict_mode')) {
            return;
        }

        $query = strtolower($query);
        $forbiddenWords = ['insert', 'update', 'delete', 'alter', 'drop', 'truncate', 'create', 'replace'];
        throw_if(Str::contains($query, $forbiddenWords), PotentiallyUnsafeQuery::fromQuery($query));
    }

    protected function getDialect(): string
    {
        $databasePlatform = DB::connection($this->connection)->getDoctrineConnection()->getDatabasePlatform();

        return Str::before(class_basename($databasePlatform), 'Platform');
    }

    /**
     * @return \Doctrine\DBAL\Schema\Table[]
     */
    protected function getTables(string $question): array
    {
        return once(function () use ($question) {
            $tables = DB::connection($this->connection)
                ->getDoctrineSchemaManager()
                ->listTables();

            if (count($tables) < config('ask-database.max_tables_before_performing_lookup')) {
                return $tables;
            }

            return $this->filterMatchingTables($question, $tables);
        });
    }

    protected function filterMatchingTables(string $question, array $tables): array
    {
        $prompt = (string) view('ask-database::prompts.tables', [
            'question' => $question,
            'tables' => $tables,
        ]);
        $prompt = rtrim($prompt, PHP_EOL);

        $matchingTables = $this->queryOpenAi($prompt, "\n");

        Str::of($matchingTables)
            ->explode(',')
            ->transform(fn (string $tableName) => trim($tableName))
            ->filter()
            ->each(function (string $tableName) use (&$tables) {
                $tables = array_filter($tables, fn ($table) => strtolower($table->getName()) === strtolower($tableName));
            });

        return $tables;
    }
}
