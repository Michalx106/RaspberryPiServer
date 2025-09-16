<?php
// Prosty przykład dynamicznej strony
$time = date("H:i:s");
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <title>Moja strona na Raspberry Pi</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      max-width: 800px;
      margin: 40px auto;
      background: #f5f5f5;
      padding: 20px;
      border-radius: 12px;
      box-shadow: 0 0 12px rgba(0,0,0,0.2);
    }
    h1 { color: #0066cc; }
    p { font-size: 18px; }
    footer { margin-top: 40px; font-size: 14px; color: #666; }
  </style>
</head>
<body>
  <h1>Witaj na mojej stronie! 🎉</h1>
  <p>Ta strona działa na <strong>Raspberry Pi + Nginx + PHP</strong>.</p>
  <p>Aktualny czas serwera to: <strong><?php echo $time; ?></strong></p>
  <footer>
    &copy; <?php echo date("Y"); ?> Michał Grzesiewicz
  </footer>
</body>
</html>
