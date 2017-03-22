/*global path*/

function checkHoldStatuses() {
  var id = $.map($('.ajaxItem'), function(i) {
    return $(i).find('.hiddenId')[0].value;
  });
  if (!id.length) {
    return;
  }
  $(".ajax-holdStatus").removeClass('hidden');
  while (id.length) {
    $.ajax({
      dataType: 'json',
      url: path + '/AJAX/JSON?method=getHoldStatuses',
      data: {id:id.splice(0,4)},
      success: handleHoldStatusResponse
    });
  }
}

function handleHoldStatusResponse(response) {
  if(response.status == 'OK') {
    $.each(response.data, function(i, result) {
      var item = $('.hiddenId[value="' + result.id + '"]').parents('.ajaxItem');
      item.find('.holdStatus').empty().append(result.hold_status_message);
      item.find(".ajax-holdStatus").removeClass('ajax-holdStatus');
    });
  } else {
    // display the error message on each of the ajax status place holder
    $(".ajax-holdStatus").empty().append(response.data);
    $(".ajax-holdStatus").removeClass('ajax-holdStatus');
  }
}

function checkItemStatuses() {
  var id = $.map($('.ajaxItem'), function(i) {
    return $(i).find('.hiddenId')[0].value;
  });
  if (!id.length) {
    return;
  }
  $(".ajax-availability").removeClass('hidden');
  while( id.length ) {
    $.ajax({
      dataType: 'json',
      url: path + '/AJAX/JSON?method=getItemStatuses',
      data: {id:id.splice(0,4)},
      success: handleItemStatusResponse
    });
  }
}

function handleItemStatusResponse(response) {  
  if(response.status == 'OK') {
    $.each(response.data, function(i, result) {
      var item = $('.hiddenId[value="' + result.id + '"]').parents('.ajaxItem');
      item.find('.status').empty().append(result.availability_message);
      item.each( function() {
        var heldItemID = $(this).find('.volumeInfo.hidden').html();
        var heldVolumes = jQuery.parseJSON(result.heldVolumes);
        if( heldItemID && heldVolumes.hasOwnProperty(heldItemID) ) {
          $(this).find('.volumeInfo').empty().append("(" + heldVolumes[heldItemID] + ")").removeClass("hidden");
        }
      } );
      var urls = JSON.parse(result.urls);
      for( var key in urls ) {
        if( urls.hasOwnProperty(key) ) {
          $('div.itemURL a[href="' + urls[key]["url"] + '"]').parents('.itemURL').removeClass("hidden");
          $('div.itemURL a[href="' + urls[key]["url"] + '"]').parents('tr').find('td.itemDetailCategory').removeClass("hidden");
        }
      }
      var leftButton = item.find('.leftButton');
      var leftButtonMenu = item.find('#holdButtonDropdown' + result.id.replace(".","") + ',#holdButtonDropdownMobile' + result.id.replace(".",""));
      if( result.isHolding ) {
        leftButton.empty().append('Holding');
      } else if( ("canCheckOut" in result) && result.canCheckOut ) {
        leftButton.prop('disabled', false);
        leftButton.wrap("<a href=\"" + result.checkoutLink + "\" target=\"loginFrame\"></a>");
        leftButton.attr('onClick', "$(this).html('<i class=\\\'fa fa-spinner bwSpinner\\\'></i>&nbsp;Loading...')");
        leftButton.empty().append('Check Out');
      } else if( ("isCheckedOut" in result) && result.isCheckedOut ) {
        leftButton.prop('disabled', false);
        leftButton.attr('data-toggle', 'dropdown');
        leftButton.attr('data-target', '#holdButtonDropdown' + result.id.replace(".","") + ',#holdButtonDropdownMobile' + result.id.replace(".",""));
        if( ("ODread" in result) && result.ODread.result ) {
          leftButtonMenu.children(".standardDropdown").append("<li><a href=\"" + result.ODread.downloadUrl + "\" target=\"_blank\"><button class=\"btn-dropdown btn-standardDropdown\">Read Now</button></a></li>");
        }
        if( ("ODlisten" in result) && result.ODlisten.result ) {
          leftButtonMenu.children(".standardDropdown").append("<li><a href=\"" + result.ODlisten.downloadUrl + "\" target=\"_blank\"><button class=\"btn-dropdown btn-standardDropdown\">Listen Now</button></a></li>");
        }
        if( ("ODwatch" in result) && result.ODwatch.result ) {
          leftButtonMenu.children(".standardDropdown").append("<li><a href=\"" + result.ODwatch.downloadUrl + "\" target=\"_blank\"><button class=\"btn-dropdown btn-standardDropdown\">Watch Now</button></a></li>");
        }
        if( ("downloadFormats" in result) && result.downloadFormats.length > 0 ) {
          var streamingVideo = false;
          var nookPeriodical = false;
          for(var k=0; k<result.downloadFormats.length; k++ ) {
            streamingVideo |= (result.downloadFormats[k].id == "video-streaming");
            nookPeriodical |= (result.downloadFormats[k].id == "periodicals-nook");
          }
          leftButtonMenu.children(".standardDropdown").append("<li><button class=\"btn-dropdown btn-standardDropdown\" onClick=\"Lightbox.get('Record','OverdriveDownload'," + result.idArgs.replace("}",",'parentURL':'" + location.pathname + location.search + "'}") + ")\">" + (streamingVideo ? "Watch Now" : "Download") + "</button></li>");
        }
        if( ("canReturn" in result) && result.canReturn ) {
          leftButtonMenu.children(".standardDropdown").append("<li><a href=\"" + result.returnLink + "\" target=\"loginFrame\"><button class=\"btn-dropdown btn-standardDropdown\" onClick=\"$(this).parents('.dropdown').siblings('.leftButton').html('<i class=\\'fa fa-spinner bwSpinner\\'></i>&nbsp;Loading...')\">Return</button></a></li>");
        }
        leftButton.empty().append('Checked Out<i class="fa fa-caret-down"></i>');
      } else if( result.itsHere && result.holdableCopyHere && result.volume_number == '' ) {
        leftButton.empty().append('It\'s Here');
      } else if( ("holdLink" in result) ) {
        leftButton.prop('disabled', false);
        leftButton.wrap("<a href=\"" + result.holdLink + "\" target=\"loginFrame\"></a>");
        leftButton.attr('onClick', "$(this).html('<i class=\\\'fa fa-spinner bwSpinner\\\'></i>&nbsp;Loading...')");
        leftButton.empty().append('Hold');
      } else if( result.holdArgs != '' ) {
        leftButton.prop('disabled', false);
        leftButton.attr('onClick', "Lightbox.get('Record','" + (result.hasVolumes ? "SelectItem" : "Hold") + "'," + result.holdArgs + ")");
        leftButton.empty().append('Hold');
      } else if( result.learnMoreURL != '' ) {
        leftButton.empty().append('Learn More');
        leftButton.prop('disabled', false);
        leftButton.attr('onClick', "window.open('" + result.learnMoreURL + "', '_blank');");
      } else {
        leftButton.empty().append('Unable to Hold');
      }
      if (typeof(result.full_status) != 'undefined'
        && result.full_status.length > 0
        && item.find('.callnumAndLocation').length > 0
      ) {
        // Full status mode is on -- display the HTML and hide extraneous junk:
        item.find('.callnumAndLocation').empty().append(result.full_status);
        item.find('.callnumber').addClass('hidden');
        item.find('.location').addClass('hidden');
        item.find('.hideIfDetailed').addClass('hidden');
        item.find('.status').addClass('hidden');
      } else if (typeof(result.missing_data) != 'undefined'
        && result.missing_data
      ) {
        // No data is available -- hide the entire status area:
        item.find('.callnumAndLocation').addClass('hidden');
      } else if (result.locationList) {
        // We have multiple locations -- build appropriate HTML and hide unwanted labels:
        item.find('.callnumber').addClass('hidden');
        item.find('.hideIfDetailed').addClass('hidden');
        item.find('.location').addClass('hidden');
        var locationListHTML = "";
        for (var x=0; x<result.locationList.length; x++) {
          locationListHTML += '<div class="groupLocation">';
          if (result.locationList[x].availability) {
            locationListHTML += '<i class="fa fa-ok text-success"></i> <span class="text-success">'
              + result.locationList[x].location + '</span> ';
          } else {
            locationListHTML += '<i class="fa fa-remove text-error"></i> <span class="text-error"">'
              + result.locationList[x].location + '</span> ';
          }
          locationListHTML += '</div>';
          locationListHTML += '<div class="groupCallnumber">';
          locationListHTML += (result.locationList[x].callnumbers)
               ?  result.locationList[x].callnumbers : '';
          locationListHTML += '</div>';
        }
        item.find('.locationDetails').removeClass('hidden');
        item.find('.locationDetails').empty().append(locationListHTML);
      } else {
        // Default case -- load call number and location into appropriate containers:
        item.find('.callnumber').empty().append(result.callnumber+'<br/>');
        item.find('.location').empty().append(
          result.reserve == 'true'
          ? result.reserve_message
          : result.location
        );
      }
      item.find(".ajax-availability").removeClass('ajax-availability');
    });
  // it was a time out.  try again.
  } else if( response.data.msg.indexOf("timed out") != -1 ) {
    $.ajax({
      dataType: 'json',
      url: path + '/AJAX/JSON?method=getItemStatuses',
      data: {id:JSON.parse(response.data.id)},
      success: handleItemStatusResponse
    });
  // display the error message on each of the ajax status place holder
  } else {
    $.each(JSON.parse(response.data.id), function(i, bib) {
      var item = $('.hiddenId[value="' + bib + '"]').parents('.ajaxItem');
      item.find(".ajax-availability").empty().append(response.data.msg);
      item.find(".ajax-availability").removeClass('ajax-availability');
    });
  }
}

$(document).ready(function() {
  if( $(".ajax-availability").length > 0 ) {
    checkItemStatuses();
  }
  if( $(".ajax-holdStatus").length > 0 ) {
    checkHoldStatuses();
  }
});