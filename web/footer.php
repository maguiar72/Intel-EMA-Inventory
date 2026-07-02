</main>
<footer class="foot">
  <?php
    try {
        $run = db()->query(
            "SELECT started_at, finished_at, status, endpoints_count "
          . "FROM collection_runs ORDER BY id DESC LIMIT 1")->fetch();
        if ($run) {
            echo 'Ultima coleta: ' . e(fmt_datetime($run['finished_at'] ?: $run['started_at']))
               . ' &mdash; status: ' . e($run['status'])
               . ' &mdash; ' . (int)$run['endpoints_count'] . ' dispositivos';
        }
    } catch (Throwable $ex) { /* silencioso no rodape */ }
  ?>
</footer>
</body>
</html>
