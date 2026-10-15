<?php

Route::get('/ping', function () {
    return response()->json(['message' => 'Laravel API is connected!']);
});


