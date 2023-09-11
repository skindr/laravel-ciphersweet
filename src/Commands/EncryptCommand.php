<?php

namespace Spatie\LaravelCipherSweet\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use ParagonIE\CipherSweet\CipherSweet as CipherSweetEngine;
use ParagonIE\CipherSweet\EncryptedRow;
use ParagonIE\CipherSweet\Exception\InvalidCiphertextException;
use ParagonIE\CipherSweet\KeyProvider\StringProvider;
use ParagonIE\CipherSweet\KeyRotation\RowRotator;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

class EncryptCommand extends Command
{
    protected $signature = 'ciphersweet:encrypt {model} {newKey} {sortDirection=asc}';

    protected $description = 'Encrypt the values of a model';

    public function handle(): int
    {
        if (! $this->ensureValidInput()) {
            return self::INVALID;
        }

        $modelClass = $this->argument('model');

        $this->encryptModelValues($modelClass);

        return self::SUCCESS;
    }

    protected function ensureValidInput(): bool
    {
        /** @var class-string<\Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted> $modelClass */
        $modelClass = $this->argument('model');

        if (! class_exists($modelClass)) {
            $this->error("Model {$modelClass} does not exist");

            return false;
        }

        $newClass = (new $modelClass());

        if (! $newClass instanceof CipherSweetEncrypted) {
            $this->error("Model {$modelClass} does not implement CipherSweetEncrypted");

            return false;
        }

        return true;
    }

    /**
     * @param class-string<\Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted> $modelClass
     *
     * @return void
     */
    protected function encryptModelValues(string $modelClass): void
    {
        $updatedRows = 0;

        $newClass = (new $modelClass());
        $dataBaseConnection = $newClass->getConnection()->getName();
        $databaseTable = sprintf('%s.%s', $newClass->getConnection()->getDatabaseName(), $newClass->getTable());

        $this->getOutput()->progressStart(DB::connection($dataBaseConnection)->table($databaseTable)->count());
        $sortDirection = $this->argument('sortDirection');

        DB::connection($dataBaseConnection)
            ->table($databaseTable)
            ->orderBy((new $modelClass())
                ->getKeyName(), $sortDirection)
            ->each(function (object $model) use ($modelClass, $newClass, $dataBaseConnection, $databaseTable, &$updatedRows) {
                $model = (array)$model;

                $oldRow = new EncryptedRow(app(CipherSweetEngine::class), $databaseTable);
                $modelClass::configureCipherSweet($oldRow);

                $newRow = new EncryptedRow(
                    new CipherSweetEngine(new StringProvider($this->argument('newKey')), $oldRow->getBackend()),
                    $databaseTable,
                );
                $modelClass::configureCipherSweet($newRow);

                $rotator = new RowRotator($oldRow, $newRow);
                if ($rotator->needsReEncrypt($model)) {
                    try {
                        [$ciphertext, $indices] = $rotator->prepareForUpdate($model);
                    } catch (InvalidCiphertextException $e) {
                        [$ciphertext, $indices] = $newRow->prepareRowForStorage($model);
                    }

                    DB::connection($dataBaseConnection)
                        ->table($databaseTable)
                        ->where($newClass->getKeyName(), $model[$newClass->getKeyName()])
                        ->update(Arr::only($ciphertext, $newRow->listEncryptedFields()));

                    foreach ($indices as $name => $value) {
                        DB::connection($dataBaseConnection)->table('blind_indexes')->updateOrInsert([
                            'indexable_type' => $newClass->getMorphClass(),
                            'indexable_id' => $model[$newClass->getKeyName()],
                            'name' => $name,
                        ], [
                            'value' => $value,
                        ]);
                    }

                    $updatedRows++;
                }

                $this->getOutput()->progressAdvance();
            });

        $this->getOutput()->progressFinish();

        $this->info("Updated {$updatedRows} rows.");
        $this->info("You can now set your config key to the new key.");
    }
}
