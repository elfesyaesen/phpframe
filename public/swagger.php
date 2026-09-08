<!DOCTYPE html>
<html lang="tr">

<head>
  <meta charset="UTF-8">
  <title>API Dokümantasyon</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist/swagger-ui.css">
</head>

<body>
  
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist/swagger-ui-bundle.js"></script>
  <script>
    SwaggerUIBundle({
      url: "http://phpframe.local/swagger.json",
      dom_id: "#swagger-ui"
    });
  </script> 
  <style>
    .swagger-ui .info {
      margin: 10px 0;
    }

    .swagger-ui .scheme-container {
      background: #fff;
      box-shadow: 0 1px 2px 0 rgba(0, 0, 0, .15);
      margin: 0 0 10px;
      padding: 10px 0;
    }

    .swagger-ui textarea {
      background: hsla(0, 0%, 100%, .8);
      border: none;
      border-radius: 4px;
      color: #3b4151;
      font-family: monospace;
      font-size: 12px;
      font-weight: 600;
      min-height: 100px;
      outline: none;
      padding: 10px;
      width: 100%;
    }

    .swagger-ui .btn-group {
      display: flex;
      padding: 10px;
    }

    .swagger-ui .execute-wrapper {
      padding: 1px;
      text-align: right;
    }

    .swagger-ui .table-container {
      padding: 10px;
    }
  </style>
</body>

</html>