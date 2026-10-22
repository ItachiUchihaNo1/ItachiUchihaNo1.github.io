<?php
declare(strict_types=1);
namespace Morshed\Core;
final class Database
{
    public \PDO $pdo;
    private array $readyFeatures=[];private array $readCache=[];private int $queryCount=0;
    public function featureReady(string $key):bool{return isset($this->readyFeatures[$key]);}
    public function markFeatureReady(string $key):void{$this->readyFeatures[$key]=true;}
    public function queryCount():int{return $this->queryCount;}
    public function __construct(array $config)
    {
        if (!str_starts_with($config['dsn'] ?? '', 'mysql:')) { throw new \RuntimeException('MySQL configuration required.'); }
        $this->pdo = new \PDO($config['dsn'], $config['user'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_TIMEOUT => 5,
        ]);
        $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->pdo->exec("SET time_zone = '+00:00'");
    }
    public function run(string $sql, array $args = []): \PDOStatement
    { if(!preg_match('/^\s*(SELECT|SHOW)\b/i',$sql)){$this->readCache=[];}$this->queryCount++;$q = $this->pdo->prepare($sql); $q->execute($args); return $q; }
    public function one(string $sql, array $args = []): ?array
    { return $this->run($sql, $args)->fetch() ?: null; }
    public function all(string $sql, array $args = []): array
    { return $this->run($sql, $args)->fetchAll(); }
    /** Request-local reference-data cache; every write invalidates it. Never caches locks or identities. */
    public function allCached(string $sql,array $args=[]):array
    {if(!preg_match('/^SELECT\b/i',$sql)||preg_match('/FOR UPDATE|LOCK IN SHARE MODE/i',$sql)){throw new \LogicException('Only unlocked reference reads may be cached.');}$key=hash('sha256',$sql.serialize($args));return $this->readCache[$key]??= $this->all($sql,$args);}
    public function tx(callable $callback): mixed
    {
        $this->readCache=[];$this->pdo->beginTransaction();
        try { $result = $callback(); $this->pdo->commit(); $this->readCache=[];return $result; }
        catch (\Throwable $e) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }  $this->readCache=[];throw $e; }
    }
}
