/*global path*/

function checkPatronHolds() {
  $('#backgroundLoaderHolds').each( function() {
    $(this).attr("src", "/MyResearch/BackgroundLoader?content=holds");
  });
}

function checkPatronCheckouts() {
  $('#backgroundLoaderCheckouts').each( function() {
    $(this).attr("src", "/MyResearch/BackgroundLoader?content=checkouts");
  });
}

$(document).ready(function() {
  if( window.self === window.top ) {
    checkPatronHolds();
    checkPatronCheckouts();
  }
});