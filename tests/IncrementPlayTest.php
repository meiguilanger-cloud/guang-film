<?php
use PHPUnit\Framework\TestCase;

class IncrementPlayTest extends TestCase
{
    private $pdo;
    private $dbFile = __DIR__.'/../backend/data.db';

    protected function setUp(): void
    {
        // Ensure a fresh DB for each test
        if (file_exists($this->dbFile)) {
            unlink($this->dbFile);
        }
        copy(__DIR__.'/../backend/db.sql', $this->dbFile); // Not a perfect copy, but placeholder
        $this->pdo = new PDO('sqlite:' . $this->dbFile);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Run schema
        $schema = file_get_contents(__DIR__.'/../backend/db.sql');
        $this->pdo->exec($schema);
        // Insert a dummy song
        $this->pdo->exec("INSERT INTO songs (title, description) VALUES ('test', 'desc')");
    }

    public function testPlayCountIncrements()
    {
        $songId = $this->pdo->query('SELECT id FROM songs WHERE title = "test"')->fetchColumn();
        $initial = $this->pdo->query('SELECT play_count FROM songs WHERE id = '.$songId)->fetchColumn();
        $this->assertEquals(0, $initial);

        // Simulate POST request to increment_play.php
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['song_id'] = $songId;
        ob_start();
        require __DIR__.'/../backend/increment_play.php';
        ob_end_clean();

        $after = $this->pdo->query('SELECT play_count FROM songs WHERE id = '.$songId)->fetchColumn();
        $this->assertEquals(1, $after);
    }
}
?>