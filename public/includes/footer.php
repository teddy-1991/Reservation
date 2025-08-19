<?php $ROOT = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); ?>
<footer class="py-5" style="background-color: 	#b0c4de; color: white;">
  <div class="container">
    <div class="row">
      <div class="col-md-6 text-center text-md-start mb-4 mb-md-0">
        <img src="<?= $ROOT ?>/images/no_background_logo.png"
     alt="Sportech Logo" style="max-width: 180px; height: 100px;" /></div>
      <div class="col-md-6 text-center text-md-end">
        <h5 class="fw-bold">Contact Us</h5>
        <p class="mb-1">
          <a href="tel:4034554951" class="text-light">403-455-4951</a>
        </p>
        <p>
          <a href="mailto:sportechgolf@gmail.com" class="text-light">sportechgolf@gmail.com</a>
        </p>
      </div>
    </div>
    <hr class="border-light my-4">
    <div class="text-center small">
      &nbsp; © Sportech Indoor Golf <?= date('Y') ?>.
    </div>
  </div>
</footer>
