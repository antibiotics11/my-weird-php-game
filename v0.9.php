#!/usr/bin/env php
<?php

/** 2026.05.24
 *
 *  TODO:
 *  - End game when player dies
 *  - Validate player movement
 *  - Implement player attack mechanics
 *  - Optimize terminal rendering
 */

declare(strict_types = 1);
cli_set_process_title("my_weird_game_i");

pcntl_async_signals(true);
gc_enabled() or gc_enable();

const CONFIG_TITLE_TEXT = "MY WEIRD PHP GAME I v0.9" . PHP_EOL .
                          "Made by ANTIBIOTICS";
const CONFIG_MAP_HEIGHT = 30;
const CONFIG_MAP_WIDTH  = 50;
const CONFIG_SPAWN_RATE = [  // max 1.0, min 0.0
  "witch" => 0.005,          // spawn at 5/1000 odds
  "ghost" => 0.010,
  "trap"  => 0.008,
  "apple" => 0.009,
  "tomb"  => 0.002,
  "cure"  => 0.004,
  "drug"  => 0.008
];
const CONFIG_SPAWN_MPILER = 1000;
const CONFIG_ENTITY_IDLEN = 10;


/** Utils
 *  test succeed 2026.05.01
 */

class random {
  public function hit(int $total, int $success): bool {
    if ($total < 0 || $success < 0) {
      throw new InvalidArgumentException("Negative rate does not exist.");
    }
    return $this->randomInt(1, $total) <= $success;
  }

  public function randomInt(int $min, int $max): int {
    try {
      return random_int($min, $max);
    } catch (Random\RandomException $e) {
      throw new RuntimeException("Failed to generate random integer.", $e->getCode(), $e);
    }
  }

  public function randomString(int $length): string {
    if ($length <= 0) {
      throw new InvalidArgumentException("Length must be at least 1.");
    }
    try {
      return bin2hex(random_bytes($length));
    } catch (Random\RandomException $e) {
      throw new RuntimeException("Failed to generate random bytes.", $e->getCode(), $e);
    }
  }
}


/** Entities
 *  test succeed
 */

enum entityType: int {
  case unknown = -1;
  case road    = 0;
  case player  = 1;
  case witch   = 2;
  case ghost   = 3;
  case trap    = 4;
  case barrier = 5;
  case apple   = 6;
  case tomb    = 7;
  case cure    = 8;
  case drug    = 9;

  public static function fromStr(string $strType): self {
    return match($strType) {
      "road"    => self::road,
      "player"  => self::player,
      "witch"   => self::witch,
      "ghost"   => self::ghost,
      "trap"    => self::trap,
      "barrier" => self::barrier,
      "apple"   => self::apple,
      "tomb"    => self::tomb,
      "cure"    => self::cure,
      "drug"    => self::drug,
      default   => self::unknown
    };
  }
}

class entityFactory {
  public static function create(
    entityType $type,
    string     $id    = ""
  ): entity {
    return match ($type) {
      entityType::road    => new road($id),
      entityType::player  => new player($id),
      entityType::witch   => new witch($id),
      entityType::ghost   => new ghost($id),
      entityType::trap    => new trap($id),
      entityType::barrier => new barrier($id),
      entityType::apple   => new apple($id),
      entityType::tomb    => new tomb($id),
      entityType::cure    => new cure($id),
      entityType::drug    => new drug($id),
      default             => new unknown($id)
    };
  }
}

abstract class entity {
  public function __construct(
    public string      $id,
    public entityType  $type,
    public ?int        $hp     = null,
    public ?int        $damage = null
  ) {}

  // Must return new coordinates
  public function move(coordinates $pos, world $world): ?coordinates {
    return $pos;
  }

  // Must return coordinates to affect
  /*
  public function interact(coordinates $pos, world $world): ?coordinates {
    return null;
  }
  */

  /**
   * @param coordinates $pos
   * @param world       $world
   * @return coordinates[]|null
   */
  public function interact(coordinates $pos, world $world): ?array {
    return null;
  }
}

trait commonAction {
  /**
   * @param  coordinates  $pos
   * @param  world        $world
   * @param  int          $scanRange
   * @param  int          $scanMethod
   * @param  entityType[] $targetType
   * @return coordinates[]|null
   */
  protected function adjacentAttack(
    coordinates $pos,
    world       $world,
    int         $scanRange  = 1,
    int         $scanMethod = scanner::SCAN_METHOD_SQUARE,
    array       $targetType = [ entityType::player ]
  ): ?array {
    $map = $world->getMap();

    $adjacent = $world->getScanner()->scan(
      basePos:    $pos,
      scanRange:  $scanRange,
      mapSize:    new coordinates($world->width, $world->height),
      map:        $map,
      scanMethod: $scanMethod
    );
    $targets = []; // coordinates[]

    foreach ($adjacent as $scanResult) {
      if (!isset($map[$scanResult->pos->y][$scanResult->pos->x])) {
        continue;
      }

      if (in_array($scanResult->type, $targetType)) {
        $targets[] = $scanResult->pos;
      }
    }

    return $targets;
  }
}


/** Neutral Entities
 *  test succeed
 */

final class road extends entity {
  public function __construct(string $id) {
    parent::__construct(
      id:     $id,
      type:   entityType::road,
    );
  }
}

final class barrier extends entity {
  public function __construct(string $id) {
    parent::__construct(
      id:     $id,
      type:   entityType::barrier,
      hp:     65535
    );
  }
}

final class apple extends entity {
  public function __construct(string $id) {
    parent::__construct(
      id:     $id,
      type:   entityType::apple,
      hp:     1,
      damage: -2  // (player hp) + (damage)
    );
  }
}

final class tomb extends entity {
  public function __construct(string $id) {
    parent::__construct(
      id:     $id,
      type:   entityType::tomb,
      hp:     10
    );
  }
}

final class cure extends entity {
  public function __construct(string $id) {
    parent::__construct(
      id:     $id,
      type:   entityType::cure,
      hp:     1,
      damage: -10
    );
  }
}

final class unknown extends entity {
  public function __construct(string $id) {
    parent::__construct(
      id:     $id,
      type:   entityType::unknown,
    );
  }
}


/** Player-hostile Entities
 *
 */

final class trap extends entity {
  public function __construct(string $id) {
    parent::__construct(
      id:     $id,
      type:   entityType::trap,
      hp:     1,
      damage: 5
    );
  }
}

final class drug extends entity {
  public function __construct(string $id) {
    parent::__construct(
      id:     $id,
      type:   entityType::drug,
      hp:     1,
      damage: 10
    );
  }
}

final class witch extends entity {
  use commonAction;
  private const int ATTACK_RANGE = 2;

  public function __construct(string $id) {
    parent::__construct(
      id:     $id,
      type:   entityType::witch,
      hp:     5,
      damage: 1
    );
  }

  // witch teleports in random coordinates
  #[Override]
  public function move(coordinates $pos, world $world): ?coordinates {
    $randomX = $pos->x;
    $randomY = $pos->y;

    $retry = 0;
    do {
      $randomX = $world->getRandom()->randomInt(1, $world->width  - 2);
      $randomY = $world->getRandom()->randomInt(1, $world->height - 2);
      
      if ($retry > 5) {
        $randomX = $pos->x;
        $randomY = $pos->y;
        break;
      }
      $retry++;
    } while (
      $world->getMap()[$randomY][$randomX]->type !== entityType::road
    );

    return new coordinates($randomX, $randomY);
  }

  // witch attacks nearby player
  #[Override]
  public function interact(coordinates $pos, world $world): ?array {
    return $this->adjacentAttack(
      pos:        $pos,
      world:      $world,
      scanRange:  self::ATTACK_RANGE
    );
  }
}

final class ghost extends entity {
  use commonAction;

  public function __construct(string $id) {
    parent::__construct(
      id:     $id,
      type:   entityType::ghost,
      damage: 1
    );
  }

  private const int ATTACK_RANGE    = 1;
  private const int MOV_RETRY_MAX   = 3;
  private const int DIRECTION_NORTH = 1;
  private const int DIRECTION_SOUTH = 2;
  private const int DIRECTION_EAST  = 3;
  private const int DIRECTION_WEST  = 4;

  // ghost pass through the block, and chase the player
  #[Override]
  public function move(coordinates $pos, world $world): ?coordinates {
    $direction   = self::DIRECTION_EAST;
    $playerFound = false;
    $map         = $world->getMap();

    // scan for nearby player first
    $adjacent = $world->getScanner()->scan(
      basePos:    $pos,
      scanRange:  5,
      mapSize:    new coordinates($world->width, $world->height),
      map:        $map,
      scanMethod: scanner::SCAN_METHOD_CROSS
    );
    foreach ($adjacent as $scanResult) {
      if ($scanResult->type != entityType::player) {
        continue;
      }

      $playerPosX = $scanResult->pos->x;
      $playerPosY = $scanResult->pos->y;
      $direction = match(true) {
        ($playerPosX == $pos->x && $playerPosY > $pos->y) => self::DIRECTION_SOUTH,
        ($playerPosX == $pos->x && $playerPosY < $pos->y) => self::DIRECTION_NORTH,
        ($playerPosY == $pos->y && $playerPosX > $pos->x) => self::DIRECTION_EAST,
        ($playerPosY == $pos->y && $playerPosX < $pos->x) => self::DIRECTION_WEST
      };
      $playerFound = true;
    }

    if (!$playerFound) {
      $direction = $world->getRandom()->randomInt(1, 4);
    }

    $nextPos = null;
    $step    = 0;
    $retry   = 0;

    do {
      $step++;
      $retry++;

      $nextPos = match($direction) {
        self::DIRECTION_SOUTH => new coordinates($pos->x, $pos->y + $step),
        self::DIRECTION_NORTH => new coordinates($pos->x, $pos->y - $step),
        self::DIRECTION_EAST  => new coordinates($pos->x + $step, $pos->y),
        self::DIRECTION_WEST  => new coordinates($pos->x - $step, $pos->y)
      };

      if (
        $nextPos->x >= $world->width  - 2 || $nextPos->x <= 1 ||
        $nextPos->y >= $world->height - 2 || $nextPos->y <= 1
      ) {
        $nextPos = $pos;
        break;
      }

    } while (
      $map[$nextPos->y][$nextPos->x]->type != entityType::road &&
      $retry < self::MOV_RETRY_MAX
    );

    return $nextPos;
  }

  // ghost attacks a living thing
  #[Override]
  public function interact(coordinates $pos, world $world): array {
    return $this->adjacentAttack(
      pos:        $pos,
      world:      $world,
      scanRange:  self::ATTACK_RANGE,
      targetType: [ entityType::player, entityType::witch ]
    );
  }
}


/** Playable Entities
 *
 */
 
final class player extends entity {
  private const int DEFAULT_HP     = 10;
  private const int DEFAULT_DAMAGE = 1;

  public function __construct(
    string $id,
    int    $hp     = self::DEFAULT_HP,
    int    $damage = self::DEFAULT_DAMAGE
  ) {
    parent::__construct(
      id:     $id,
      type:   entityType::player,
      hp:     $hp,
      damage: $damage
    );
  }

  #[Override]
  public function move(coordinates $pos, world $world): ?coordinates {
    $key = terminal::detect();
    if ($key === null) {
      return $pos;
    }

    return match($key) {
      keyType::up    => new coordinates($pos->x, $pos->y - 1),
      keyType::down  => new coordinates($pos->x, $pos->y + 1),
      keyType::right => new coordinates($pos->x + 1, $pos->y),
      keyType::left  => new coordinates($pos->x - 1, $pos->y),
      default        => $pos
    };
  }
}


/** Map-related
 *  test succeed
 *
 *  TODO: Add diamond scan
 */

// universal coordinate pair
readonly class coordinates {
  public function __construct(
    public int $x,
    public int $y
  ) {}
}

readonly class scanResult {
  public function __construct(
    public coordinates $pos,
    public entityType  $type
  ) {}
}

class scanner {
  public const int SCAN_METHOD_CROSS   = 0;
  public const int SCAN_METHOD_SQUARE  = 1;
  public const int SCAN_METHOD_DIAMOND = 2;

  /**
   * @param  coordinates $basePos
   * @param  int         $scanRange
   * @param  coordinates $mapSize
   * @param  entity[][]  $map
   * @return scanResult[]
   * @throws InvalidArgumentException
   */
  public function scan(
    coordinates $basePos,
    int         $scanRange,
    coordinates $mapSize,
    array       $map,
    int         $scanMethod = self::SCAN_METHOD_SQUARE
  ): array {
    return match ($scanMethod) {
      self::SCAN_METHOD_CROSS  => $this->scan_cross(
        $basePos, $scanRange, $mapSize, $map
      ),
      self::SCAN_METHOD_SQUARE => $this->scan_square(
        $basePos, $scanRange, $mapSize, $map
      ),
      // self::SCAN_METHOD_DIAMOND => TODO
      default => throw new InvalidArgumentException("Undefined scan method.")
    };
  }

  protected function scan_cross(
    coordinates $basePos,
    int         $scanRange,
    coordinates $mapSize,
    array       $map
  ): array {
    $result = [];
    $baseX  = $basePos->x;
    $baseY  = $basePos->y;

    for ($y = $baseY - $scanRange; $y <= $baseY + $scanRange; $y++) {
      if ($y != $baseY && $y < $mapSize->y && $y >= 0) {
        $result[] = new scanResult(
          pos:  new coordinates($baseX, $y),
          type: $map[$y][$baseX]->type
        );
      }
    }
    for ($x = $baseX - $scanRange; $x <= $baseX + $scanRange; $x++) {
      if ($x != $baseX && $x < $mapSize->x && $x >= 0) {
        $result[] = new scanResult(
          pos:  new coordinates($x, $baseY),
          type: $map[$baseY][$x]->type
        );
      }
    }
    return $result;
  }

  protected function scan_square(
    coordinates $basePos,
    int         $scanRange,
    coordinates $mapSize,
    array       $map
  ): array {
    $result = [];
    $baseX  = $basePos->x;
    $baseY  = $basePos->y;

    for ($y = $baseY - $scanRange; $y <= $baseY + $scanRange; $y++) {
      for ($x = $baseX - $scanRange; $x <= $baseX + $scanRange; $x++) {
        if ($x == $baseX && $y == $baseY) {
          continue;
        }
        if (
          $x >= $mapSize->x || $y >= $mapSize->y ||
          $x < 0            || $y < 0
        ) {
          continue;
        }
        $result[] = new scanResult(
          pos:  new coordinates($x, $y),
          type: $map[$y][$x]->type
        );
      }
    }
    return $result;
  }
}


/** World
 *  never tested
 */

class world {
  /** @var Entity[][] */
  protected array   $map;
  protected scanner $scanner;
  protected random  $random;
  protected array   $idTable;
  protected ?player $playerRef;

  public function getMap(): array {
    return $this->map;
  }

  public function getScanner(): scanner {
    return $this->scanner;
  }

  public function getRandom(): random {
    return $this->random;
  }

  public function __construct(
    public readonly int   $width,
    public readonly int   $height,
    public readonly array $spawnRate   = [],    // max 1.0, min 0.0
    public readonly int   $spawnMplier = 1000,
    public readonly int   $entityIdLen = 10
  ) {
    $this->scanner   = new scanner();
    $this->random    = new random();
    $this->idTable   = [];
    $this->playerRef = null;

    $this->map = [];
    for ($y = 0; $y < $this->height; $y++) {
      $this->map[$y] = [];
      for ($x = 0; $x < $this->width; $x++) {
        $this->map[$y][$x] = null;
      }
    }
  }

  public function spawn(coordinates $pos, entityType $type, ?string $id = null): void {
    if ($id === null) {
      do {
        $id = $this->random->randomString($this->entityIdLen);
      } while (in_array($id, $this->idTable));
      $this->idTable[] = $id;
    }

    $this->map[$pos->y][$pos->x] = entityFactory::create(
      type: $type,
      id:   $id
    );
  }

  public function spawnByStrType(
    coordinates $pos,
    string      $strType,
    ?string     $id      = null
  ): void {
    $type = entityType::fromStr($strType);
    $this->spawn($pos, $type, $id);
  }

  public function reset(): void {
    for ($y = 0; $y < $this->height; $y++) {
      for ($x = 0; $x < $this->width; $x++) {
        $currentPos = new coordinates($x, $y);

        if ($y == 0 || $y == $this->height - 1) {
          $this->spawn($currentPos, entityType::barrier);
          continue;
        }
        if ($x == 0 || $x == $this->width - 1) {
          $this->spawn($currentPos, entityType::barrier);
          continue;
        }

        if ($x == 1 && $y == 1) {
          $this->spawn($currentPos, entityType::player);
          $this->playerRef = $this->map[$y][$x];
          continue;
        }

        foreach ($this->spawnRate as $strType => $rate) {
          $hit = $this->random->hit(
            total:   (int)(1.0   * $this->spawnMplier),
            success: (int)($rate * $this->spawnMplier)
          );
          if ($hit) {
            $this->spawnByStrType($currentPos, $strType);
            if ($this->map[$y][$x] instanceof entity) {
              break;
            }
          }
        }
        
        if ($this->map[$y][$x] === null) {
          $this->spawn($currentPos, entityType::road);
        }
      } // 2nd loop
    } // 1st loop
  } // end function
  
  public function update(callable $afterAttackJob, callable $afterMoveJob): void {
    $ignoreFlags = [];
    for ($y = 0; $y < $this->height; $y++) {
      $ignoreFlags[$y] = [];
      for ($x = 0; $x < $this->width; $x++) {
        $ignoreFlags[$y][$x] = false;
      }
    }

    for ($y = 0; $y < $this->height; $y++) {
      for ($x = 0; $x < $this->width; $x++) {
        if ($ignoreFlags[$y][$x]) {
          continue;
        }

        $actor    = $this->map[$y][$x];
        $actorPos = new coordinates($x, $y);

        if ($this->handleAttack(
          actorPos: $actorPos,
          actor:    $actor,
          targets:  $actor->interact($actorPos, $this)
        )) {
          //$this->waitFor(100000);
        }

        $afterAttackJob([
          "info_1_key" => "Player HP",
          "info_1_val" => $this->playerRef->hp
        ]);

        $nextPos = $actor->move($actorPos, $this);
        if ($this->handleMove(
          actorPos: $actorPos,
          actor:    $actor,
          nextPos:  $nextPos
        )) {
          $this->waitFor(100000);
          $ignoreFlags[$nextPos->y][$nextPos->x] = true;
        }

        $afterMoveJob([
          "info_1_key" => "Player HP",
          "info_1_val" => $this->playerRef->hp
        ]);
      }
    }
  }

  private function handleAttack(
    coordinates $actorPos,
    entity      $actor,
    ?array      $targets     // coordinates[]|null
  ): bool {
    if ($targets === null) {
      return false;
    }
    if ($actor->damage === null) {
      return false;
    }

    foreach ($targets as $targetPos) {
      $target = &$this->map[$targetPos->y][$targetPos->x];
      if ($target->hp === null) {
        continue;
      }

      $target->hp -= $actor->damage;
      if ($target->hp > 0) {
        continue;
      }

      // if target dead
      $newTarget = match($target->type) {
        entityType::player,
        entityType::witch    => entityFactory::create(entityType::tomb,    $target->id),
        entityType::barrier,
        entityType::tomb,
        entityType::ghost,
        entityType::apple,
        entityType::cure,
        entityType::drug     => entityFactory::create(entityType::road,    $target->id),
        default              => entityFactory::create(entityType::unknown, $target->id)
      };

      $this->map[$targetPos->y][$targetPos->x] = $newTarget;
    }

    return true;
  }

  private function handleMove(
    coordinates  $actorPos,
    entity       $actor,
    ?coordinates $nextPos
  ): bool {
    if ($nextPos === null) {
      return false;
    }
    if ($actorPos->x == $nextPos->x && $actorPos->y == $nextPos->y) {
      return false;
    }
    if (!isset($this->map[$nextPos->y][$nextPos->x])) {
      return false;
    }

    /*
    $prevEntity = $this->map[$nextPos->y][$nextPos->x];
    $this->map[$nextPos->y][$nextPos->x] = $actor;
    $this->map[$actorPos->y][$actorPos->x] = $prevEntity;
    */

    $this->map[$nextPos->y][$nextPos->x] = $actor;
    $this->spawn(
      pos:  new coordinates($actorPos->x, $actorPos->y),
      type: entityType::road
    );
    return true;
  }

  private function waitFor(int $microSec = 100000): void {
    usleep($microSec);
  }
}


/** Renderer
 *  test succeed 2026.04.30
 */
 
enum entityLook: string {
  case road    = "⬜";
  case player  = "😀";
  case witch   = "🧙";
  case ghost   = "👻";
  case trap    = "🪤";
  case barrier = "🚧";
  case apple   = "🍎";
  case tomb    = "🪦";
  case cure    = "💊";
  case drug    = "💊​";
  case unknown = "❓";
}

class renderer {
  public function render(array $map, string $title = "", array $additional = []): string {
    return sprintf("%s%s%s%s",
      $this->loadInfo($map, $title, $additional),
      PHP_EOL,
      $this->loadEntities($map),
      PHP_EOL
    );
  }

  protected function loadEntities(array $map): string {
    $rendered = "";
    for ($y = 0; $y < count($map); $y++) {
      for ($x = 0; $x < count($map[$y]); $x++) {
        $entity      = $map[$y][$x];
        $entityLook  = entityLook::unknown;

        if ($entity instanceof entity) {
          $entityLook = match ($entity->type) {
            entityType::road    => entityLook::road,
            entityType::player  => entityLook::player,
            entityType::witch   => entityLook::witch,
            entityType::ghost   => entityLook::ghost,
            entityType::trap    => entityLook::trap,
            entityType::barrier => entityLook::barrier,
            entityType::apple   => entityLook::apple,
            entityType::tomb    => entityLook::tomb,
            entityType::cure    => entityLook::cure,
            entityType::drug    => entityLook::drug,
            default             => entityLook::unknown
          };
        }
        $rendered .= $entityLook->value;
      }
      $rendered .= PHP_EOL;
    }
    return $rendered;
  }

  protected function loadInfo(array $map, string $title, array $additional): string {
    $header = sprintf("%s%s%s: %d%s",
      $title,
      PHP_EOL,
      $additional["info_1_key"] ?? "unknown",
      $additional["info_1_val"] ?? -1,
      PHP_EOL
    );
    $dividingLine = sprintf("%s%s",
      str_repeat("=", count($map[0]) * 2),
      PHP_EOL
  );

    return $header . $dividingLine;
  }
}

enum keyType: int {
  case up    = 1;
  case down  = 2;
  case right = 3;
  case left  = 4;

  public static function fromStr(string $keyPress): ?self {
    return match($keyPress) {
      "w" => self::up,
      "s" => self::down,
      "d" => self::right,
      "a" => self::left,
      default  => null
    };
  }
}

class terminal {
  protected static ?self $instance = null;
  public static function getInstance(): self {
    self::$instance ??= new self();
    return self::$instance;
  }

  /** @var resource */ protected $stream;

  protected SplQueue $errors;

  protected function __construct() {
    $this->stream = STDOUT;
    $this->errors = new SplQueue();
  }

  public function __destruct() {
    fclose($this->stream);
  }

  public function write(string $output = ""): int {
    $outputLength = strlen($output);
    $result = 0;
    try {
      $result = fwrite($this->stream, $output, $outputLength);
      if ($result === false) {
        throw new RuntimeException(sprintf(
          "Failed to write %d bytes to stream.",
          $outputLength
        ));
      }
    } catch (Throwable $e) {
      $this->errors->enqueue($e);
    }

    return $result;
  }

  public function getErrors(): iterable {
    while ($this->errors->valid()) {
      yield $this->errors->dequeue();
    }
  }

  public const string CLS = "\033[2J\033[;H";

  public static function print(string $output): void {
    self::getInstance()->write($output);
  }

  public static function clear(): void {
    self::getInstance()->write(self::CLS);
  }

  public static function enableRawMode(): void {
    shell_exec('stty -icanon -echo');
  }

  public static function disableRawMode(): void {
    shell_exec('stty sane');
  }

  public static function detect(): ?keyType {
    $keyPress = fgetc(STDIN);
    if ($keyPress !== false) {
      return keyType::fromStr($keyPress);
    }
    return null;
  }
}


/** Entry
 *  never tested
 */

final class game {
  private ?world    $world;
  private ?renderer $renderer;
  private bool      $fin;

  public function __construct(
    public readonly int   $argc,
    public readonly array $argv
  ) {
    $mapWidth  = $this->argv[1] ?? CONFIG_MAP_WIDTH;
    $mapHeight = $this->argv[2] ?? CONFIG_MAP_HEIGHT;
    $this->world = new world(
      width:       $mapWidth,
      height:      $mapHeight,
      spawnRate:   CONFIG_SPAWN_RATE,
      spawnMplier: CONFIG_SPAWN_MPILER,
      entityIdLen: CONFIG_ENTITY_IDLEN
    );

    $this->renderer = new renderer();
    $this->fin      = false;
  }

  public function run(): int {
    try {
      terminal::enableRawMode();
      $this->world->reset();
      //debug_zval_dump($this->world->getMap());

      while (!$this->fin) {
        $this->world->update(
          afterAttackJob: function (array $additional = []): void {
            terminal::clear();
            terminal::print($this->renderer->render(
              map:        $this->world->getMap(),
              title:      CONFIG_TITLE_TEXT,
              additional: $additional
            ));
          },
          afterMoveJob: function (array $additional = []): void {
            terminal::clear();
            terminal::print($this->renderer->render(
              map:        $this->world->getMap(),
              title:      CONFIG_TITLE_TEXT,
              additional: $additional
            ));
          }
        );
      }

      return 0;
    } catch (Throwable $e) {
      /*
      terminal::print(sprintf(
        "game terminated by an error: %s%s",
        $e->getMessage(), PHP_EOL
      ));
      */
      terminal::print(sprintf(
        "[%s] %s%sFile: %s:%d%sTrace:%s%s%s",
        get_class($e),
        $e->getMessage(),
        PHP_EOL,
        $e->getFile(),
        $e->getLine(),
        PHP_EOL,
        PHP_EOL,
        $e->getTraceAsString(),
        PHP_EOL
      ));
      return 1;
    } finally {
      terminal::disableRawMode();
    }
  }

  public function close(): void {
    terminal::clear();
    terminal::disableRawMode();
    $this->fin = true;
  }
}

$game = new game($_SERVER["argc"], $_SERVER["argv"]);
pcntl_signal(SIGINT,  [ $game, "close" ]);
pcntl_signal(SIGTERM, [ $game, "close" ]);

exit($game->run());
