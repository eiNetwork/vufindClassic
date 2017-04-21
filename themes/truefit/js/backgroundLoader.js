/*global path*/

function checkPatronHolds() {
  $('#backgroundLoaderHolds').each( function() {
    $(this).attr("src", "/MyResearch/BackgroundLoader?content=holds&backgroundLoad=true");
  });
}

function checkPatronCheckouts() {
  $('#backgroundLoaderCheckouts').each( function() {
    $(this).attr("src", "/MyResearch/BackgroundLoader?content=checkouts&backgroundLoad=true");
  });
}

function ajaxLoadList(id) {
  $('.ajaxListID' + id).each( function() {
    $.ajax({
      dataType: 'json',
      url: path + '/AJAX/JSON?method=getListContents',
      data: {id:[id], 
             page:[$(this).find(".ajaxListPage").attr("value")], 
             path:[$(this).find(".ajaxListSortControls").html()], 
             sort:[$(this).find(".ajaxListSort").attr("value")]},
      success: handleListContentResponse
    })
  });
}

function handleListContentResponse(response) {
  if(response.status == 'OK') {
    $('.ajaxListID' + response.data.id).each( function() {
      $(this).find(".ajaxListContents").append(response.data.html);

      // clean up the overlap for long format names
      $(".highlightContainer").each( function() {
        if( $(this).children("table").outerWidth() > $(this).outerWidth() ) {
          var margin = 5 + $(this).next().outerHeight() - $(this).children("table").position().top;
          if( margin > 0 ) {
            $(this).children("table").css({"margin-top":(margin + "px")});
          }
        }
      } );

      // if we need to continue going, grab the next page
      if( response.data.continue ) {
        $(this).find(".ajaxListPage").attr("value", parseInt($(this).find(".ajaxListPage").attr("value")) + 1);
        ajaxLoadList(response.data.id);
      // stop loading, enable the sort/bulk buttons and grab item statuses
      } else {
        $(this).find(".ajaxListContents .loadingWall").remove();

        // sort buttons
        $(this).find(".ajaxListSortControls").html(response.data.sortHtml).parents("tr").css({"display":"inherit"});
        // bulk buttons
        $(this).find(".ajaxListBulkButtons").html(response.data.bulkHtml);
        $(this).find(".ajaxListBulkButtons").next().append($(this).find(".ajaxItem").find("label.pull-left").clone());

        // item statuses
        $(document).ready(function() {
          if( $(".ajax-availability").length > 0 ) {
            checkItemStatuses();
          }
          if( $(".ajax-holdStatus").length > 0 ) {
            checkHoldStatuses();
          }
        });

        $(this).removeClass("ajaxListID" + response.data.id);
      }
    });
  }
}


$(document).ready(function() {
  if( window.self === window.top ) {
    checkPatronHolds();
    checkPatronCheckouts();
  }
});