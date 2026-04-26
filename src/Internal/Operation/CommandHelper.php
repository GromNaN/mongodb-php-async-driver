<?php

declare(strict_types=1);

namespace MongoDB\Internal\Operation;

use MongoDB\BSON\Document;
use MongoDB\Driver\Exception\InvalidArgumentException;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\ServerApi;
use MongoDB\Driver\Session;
use MongoDB\Driver\WriteConcern;

use function array_key_first;
use function is_array;
use function is_object;
use function lcfirst;

/**
 * Utility class for preparing MongoDB command documents before wire transmission.
 *
 * Handles injection of cross-cutting fields that every command must carry:
 * `$db`, `$readPreference`, `readConcern`, `writeConcern`, `lsid`,
 * `apiVersion` / `apiStrict` / `apiDeprecationErrors`.
 *
 * @internal
 */
final class CommandHelper
{
    // -----------------------------------------------------------------
    // Command name sets
    // -----------------------------------------------------------------

    private const WRITE_COMMANDS = [
        'insert'              => true,
        'update'              => true,
        'delete'              => true,
        'findAndModify'       => true,
        'findandmodify'       => true,
        'bulkWrite'           => true,
        'createIndexes'       => true,
        'dropIndexes'         => true,
        'dropIndex'           => true,
        'create'              => true,
        'drop'                => true,
        'createCollection'    => true,
        'dropCollection'      => true,
        'renameCollection'    => true,
        'createSearchIndexes' => true,
        'updateSearchIndex'   => true,
        'dropSearchIndex'     => true,
    ];

    private const READ_COMMANDS = [
        'find'            => true,
        'aggregate'       => true,
        'count'           => true,
        'distinct'        => true,
        'mapReduce'       => true,
        'getMore'         => true,
        'listCollections' => true,
        'listDatabases'   => true,
        'listIndexes'     => true,
        'explain'         => true,
        'collStats'       => true,
        'dbStats'         => true,
    ];

    // -----------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------

    /**
     * Inject standard wire-protocol fields into a command document.
     *
     * The returned array is ready for BSON encoding and wire transmission.
     * The source $command is not mutated.
     *
     * @param array|object        $command        Raw command document.
     * @param string              $db             Target database name.
     * @param ReadPreference|null $readPreference If non-primary and topology is
     *                                            not Single, injected as `$readPreference`.
     * @param ReadConcern|null    $readConcern    Injected only when not default.
     * @param WriteConcern|null   $writeConcern   Injected only when not default.
     * @param Session|null        $session        Provides `lsid`.
     * @param ServerApi|null      $serverApi      Provides `apiVersion` + optional fields.
     *
     * @return array The normalised, fully-decorated command document.
     */
    public static function prepareCommand(
        array|object $command,
        string $db,
        ?ReadPreference $readPreference = null,
        ?ReadConcern $readConcern = null,
        ?WriteConcern $writeConcern = null,
        ?Session $session = null,
        ?ServerApi $serverApi = null,
    ): array {
        // 1. Normalise to array. MongoDB\BSON\Document must be decoded via toPHP()
        //    because casting to array yields internal PHP properties, not document fields.
        if ($command instanceof Document) {
            $command = (array) $command->toPHP(['root' => 'array', 'document' => 'array']);
        }

        $doc = is_array($command) ? $command : (array) $command;

        // 2. Target database.
        $doc['$db'] = $db;

        // 3. Read preference — inject only for non-primary preferences and only
        //    when the topology is not Single (caller is responsible for the
        //    topology check; we encode when the object is provided and non-primary).
        if (
            $readPreference !== null
            && $readPreference->getModeString() !== ReadPreference::PRIMARY
        ) {
            $rpDoc = ['mode' => $readPreference->getModeString()];

            $tagSets = $readPreference->getTagSets();
            if ($tagSets !== []) {
                $rpDoc['tags'] = $tagSets;
            }

            $maxStaleness = $readPreference->getMaxStalenessSeconds();
            if ($maxStaleness !== ReadPreference::NO_MAX_STALENESS) {
                $rpDoc['maxStalenessSeconds'] = $maxStaleness;
            }

            $hedge = @$readPreference->getHedge();
            if ($hedge !== null) {
                $rpDoc['hedge'] = $hedge;
            }

            $doc['$readPreference'] = $rpDoc;
        }

        // 4. Read concern — skip when default (null level).
        if ($readConcern !== null && ! $readConcern->isDefault()) {
            $rcDoc = [];
            if ($readConcern->getLevel() !== null) {
                $rcDoc['level'] = $readConcern->getLevel();
            }

            $doc['readConcern'] = $rcDoc;
        }

        // 5. Write concern — skip when default (w:1, j:null, wtimeout:0).
        if ($writeConcern !== null && ! $writeConcern->isDefault()) {
            $wcDoc = ['w' => $writeConcern->getW()];

            if ($writeConcern->getWtimeout() !== 0) {
                $wcDoc['wtimeout'] = $writeConcern->getWtimeout();
            }

            if ($writeConcern->getJournal() !== null) {
                $wcDoc['j'] = $writeConcern->getJournal();
            }

            $doc['writeConcern'] = $wcDoc;
        }

        // 6. Logical session ID.
        if ($session !== null) {
            $doc['lsid'] = $session->getLogicalSessionId();
        }

        // 7. Stable API fields.
        if ($serverApi !== null) {
            $doc['apiVersion'] = $serverApi->getVersion();

            if ($serverApi->isStrict() !== null) {
                $doc['apiStrict'] = $serverApi->isStrict();
            }

            if ($serverApi->isDeprecationErrors() !== null) {
                $doc['apiDeprecationErrors'] = $serverApi->isDeprecationErrors();
            }
        }

        return $doc;
    }

    /**
     * Return true when $commandName is a known write command.
     */
    public static function isWriteCommand(string $commandName): bool
    {
        return isset(self::WRITE_COMMANDS[lcfirst($commandName)])
            || isset(self::WRITE_COMMANDS[$commandName]);
    }

    /**
     * Return true when $commandName is a known read-only command.
     */
    public static function isReadCommand(string $commandName): bool
    {
        return isset(self::READ_COMMANDS[lcfirst($commandName)])
            || isset(self::READ_COMMANDS[$commandName]);
    }

    /**
     * Extract the command name (i.e. the key of the first element).
     *
     * @throws InvalidArgumentException if the document is empty.
     */
    public static function getCommandName(array|object $command): string
    {
        if ($command instanceof Document) {
            $command = (array) $command->toPHP(['root' => 'array', 'document' => 'array']);
        } elseif (is_object($command)) {
            $command = (array) $command;
        }

        if ($command === []) {
            throw new InvalidArgumentException('Empty command document');
        }

        return (string) array_key_first($command);
    }
}
