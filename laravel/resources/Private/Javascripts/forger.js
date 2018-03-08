$(document).ready(function () {

	/**
	 * Special case for Gerrit Overview
	 */
	var chart2;

	/**
	 * Graph Logic
	 */
	$('.graph').each(function() {
		var docType = $(this).data('type');
		var view = $(this).data('graph');
		var chartType = $(this).data('chart');
		var chartTitle = $(this).data('charttitle');
		var graphConfig = $(this).data('graphs');
		var divId = $(this).attr('id');
		switch(chartType) {
			case 'Velocity':
				drawVelocityChart(docType, view, divId, chartTitle, graphConfig);
				break;
			case 'Overview':
				drawOverviewChart(docType, view, divId, chartTitle, graphConfig);
				break;
			case 'BarHorizontal':
				drawBarHorizontalChart(docType, view, divId, chartTitle, graphConfig);
				break;
			case 'Line':
				drawLineChart(docType, view, divId, chartTitle, graphConfig);
				break;
			case 'Pie':
				drawPieChart(docType, view, divId, chartTitle, graphConfig);
				break;
			case 'StackedArea':
				drawStackedAreaChart(docType, view, divId, chartTitle, graphConfig);
				break;
			default:
				$(divId).html('Chart of type '+chartType+ 'is not implemented yet');
		}
	});

	function drawVelocityChart(docType, view, divId, chartTitle, graphConfig) {
    var legend = {
      "horizontalGap": 5,
      "maxColumns": 3,
      "position": "absolute",
      "align": "center",
      "top": 20,
      "left": 0,
      "backgroundAlpha": 0.5,
      "useGraphSettings": true,
      "markerSize": 10,
      "markerBorderThickness": 10,
      "marginTop": 0
    };
    var settings = {
      marginTop: 40,
      marginLeft: 20,
      marginBottom: 20,
      marginRight: 20
    };
    var imagePath = '/js/amcharts/images/';
    var chartCursor = {
      "cursorPosition": "mouse",
      "pan": true,
      "valueLineEnabled":true,
      "valueLineBalloonEnabled":true
    };
    return AmCharts.makeChart(divId, {
      "dataLoader": {
        "url": "/graphs/" + docType + "/" + view
      },
      "type": "serial",
      "theme": "none",
      "autoMargins": true,
      "legend": legend,
      "marginLeft": settings.marginLeft,
      "marginBottom": settings.marginBottom,
      "marginTop": settings.marginTop,
      "marginRight": settings.marginRight,
      "pathToImages": imagePath,
      "titles": [
        {
          "text": chartTitle,
          "size": 15
        }
      ],
      "valueAxes": [{
        "axisAlpha": 0,
        "dashLength": 4,
        "position": "left"
      }],
      "graphs": graphConfig,
      "chartCursor": chartCursor,
      "dataDateFormat": "YYYY-MM-DD",
      "categoryField": "date",
      "chartScrollbar": {
        "graph": "fromGraph",
        "scrollbarHeight": 30
      },
      "categoryAxis": {
        "parseDates": true,
        "dashLength": 1,
        "minorGridEnabled": true,
        "position": "top"
      }
    });
	}

	function drawOverviewChart(docType, view, divId, chartTitle) {
    var settings = {
      marginTop: 40,
      marginLeft: 20,
      marginBottom: 20,
      marginRight: 20
    };
    var imagePath = '/js/amcharts/images/';
    var chartCursor = {
      "cursorPosition": "mouse",
      "pan": true,
      "valueLineEnabled":true,
      "valueLineBalloonEnabled":true
    };
    chart2 = AmCharts.makeChart(divId, {
      "dataLoader": {
        "url": "/graphs/" + docType + "/" + view
      },
      "type": "serial",
      "theme": "none",
      "legend": {
        "maxColumns": 3,
        "position": "absolute",
        "top": 55,
        "align": "center",
        "backgroundAlpha": 0.5,
        "useGraphSettings": true,
        "markerSize": 10,
        "markerBorderThickness": 10,
        "marginTop": 0
      },
      "marginLeft": settings.marginLeft,
      "marginBottom": settings.marginBottom,
      "marginTop": 100,
      "marginRight": settings.marginRight,
      "pathToImages": imagePath,
      "dataDateFormat": "YYYY-MM-DD",
      "titles": [
        {
          "text": chartTitle,
          "size": 15
        }
      ],
      "valueAxes": [{
        "id":"v1",
        "axisAlpha": 0,
        "position": "left"
      }],
      "graphs": [
        {
          "id": "g1",
          "bullet": "round",
          "bulletBorderAlpha": 1,
          "bulletColor": "#538bb3",
          "bulletSize": 5,
          "hideBulletsCount": 50,
          "lineThickness": 2,
          "lineColor": '#538bb3',
          "title": "New",
          "useLineColorForBulletBorder": true,
          "valueField": "NEW"
        },
        {
          "id": "g2",
          "bullet": "round",
          "bulletBorderAlpha": 1,
          "bulletColor": "#5abc55",
          "bulletSize": 5,
          "hideBulletsCount": 50,
          "lineThickness": 2,
          "lineColor": '#5abc55',
          "title": "Merged",
          "useLineColorForBulletBorder": true,
          "valueField": "MERGED"
        },
        {
          "id": "g3",
          "bullet": "round",
          "bulletBorderAlpha": 1,
          "bulletColor": "#dd123d",
          "bulletSize": 5,
          "hideBulletsCount": 50,
          "lineThickness": 1,
          "lineColor": '#dd123d',
          "title": "Abandoned",
          "useLineColorForBulletBorder": true,
          "valueField": "ABANDONED"
        }],
      "chartScrollbar": {
        "graph": "g2",
        "scrollbarHeight": 30
      },
      "chartCursor": chartCursor,
      "categoryField": "date",
      "categoryAxis": {
        "parseDates": true,
        "dashLength": 1,
        "minorGridEnabled": true,
        "position": "top"
      }
    });
	}

	function drawBarHorizontalChart(docType, view, divId, chartTitle, graphConfig) {
			var imagePath = '/js/amcharts/images/';
			return AmCharts.makeChart(divId, {
        "dataLoader": {
          "url": "/graphs/" + docType + "/" + view
        },
				"type": "serial",
				"theme": "none",
				"autoMargins": true,
				"marginLeft": 20,
				"marginBottom": 40,
				"rotate": true,
				"pathToImages": imagePath,
				"titles": [
					{
						"text": chartTitle,
						"size": 15
					}
				],
				"valueAxes": [{
					"stackType": "regular",
					"gridColor":"#000000",
					"gridAlpha": 0.15,
					"dashLength": 1,
					"autoGridCount": false,
					"labelFrequency": 3,
					"labelRotation": 45,
					"gridCount": 25
				}],
				"graphs": graphConfig,
				"categoryField": "panel",
				"categoryAxis": {
					"gridPosition": "start",
					"gridAlpha": 0.15,
					"tickPosition":"start",
					"tickLength":20,
					"dashLength": 1
				}
			});
	}

	function drawLineChart(docType, view, divId, chartTitle, graphConfig) {
			var legend = {
				"horizontalGap": 5,
				"maxColumns": 3,
				"position": "absolute",
				"align": "center",
				"top": 20,
				"left": 0,
				"backgroundAlpha": 0.5,
				"useGraphSettings": true,
				"markerSize": 10,
				"markerBorderThickness": 10,
				"marginTop": 0
			};
			var settings = {
				marginTop: 40,
				marginLeft: 20,
				marginBottom: 20,
				marginRight: 20
			};
			var imagePath = '/js/amcharts/images/';
			var chartCursor = {
				"cursorPosition": "mouse",
				"pan": true,
				"valueLineEnabled":true,
				"valueLineBalloonEnabled":true
			};
			var chart = AmCharts.makeChart(divId, {
      "dataLoader": {
        "url": "/graphs/" + docType + "/" + view
      },
      "type": "serial",
      "theme": "none",
      "autoMargins": true,
      "legend": legend,
      "marginLeft": settings.marginLeft,
      "marginBottom": settings.marginBottom,
      "marginTop": settings.marginTop,
      "marginRight": settings.marginRight,
      "pathToImages": imagePath,
      "titles": [
        {
          "text": chartTitle,
          "size": 15
        }
      ],
      "valueAxes": [{
        "axisAlpha": 1,
        "dashLength": 4,
        "position": "left"
      }],
      "graphs": graphConfig,
      "chartCursor": chartCursor,
      "dataDateFormat": "YYYY-MM-DD",
      "chartScrollbar": {
        "graph": "fromGraph",
        "scrollbarHeight": 30
      },
      "categoryField": "date",
      "categoryAxis": {
        "parseDates": true,
        "axisAlpha": 0,
        "minHorizontalGap":25,
        "gridAlpha": 0,
        "tickLength": 0,
        "twoLineMode":true,
        "dateFormats":[{
          period: 'fff',
          format: 'JJ:NN:SS'
        }, {
          period: 'ss',
          format: 'JJ:NN:SS'
        }, {
          period: 'mm',
          format: 'JJ:NN'
        }, {
          period: 'hh',
          format: 'JJ:NN'
        }, {
          period: 'DD',
          format: 'DD'
        }, {
          period: 'WW',
          format: 'DD'
        }, {
          period: 'MM',
          format: 'MMM'
        }, {
          period: 'YYYY',
          format: 'YYYY'
        }]
      }
    });
    chart.addListener("clickGraphItem", function(clickedItem) {
      if(clickedItem.target.bulletColorR === '#dd123d') {
        //new tickets
        window.open("https://forge.typo3.org/projects/typo3cms-core/issues?set_filter=1&f[]=status_id&op[status_id]=*&f[]=created_on&op[created_on]==&v[created_on][]="+clickedItem.item.dataContext.date, "_blank");
      } else if(clickedItem.target.bulletColorR === '#5abc55') {
        //closed tickets
        window.open("https://forge.typo3.org/projects/typo3cms-core/issues?set_filter=1&f[]=status_id&op[status_id]=c&f[]=updated_on&op[updated_on]==&v[updated_on][]="+clickedItem.item.dataContext.date, "_blank")
      }
    });
	}

	function drawPieChart(docType, view, divId, chartTitle) {
    return AmCharts.makeChart(divId, {
      "dataLoader": {
        "url": "/graphs/" + docType + "/" + view
      },
      "type": "pie",
      "theme": "none",
      "marginLeft": 0,
      "marginBottom": 0,
      "marginTop": 0,
      "marginRight": 0,
      "titles": [
        {
          "text": chartTitle,
          "size": 15
        }
      ],
      "titleField": "name",
      "valueField": "value",
      "labelRadius": -40,
      //"radius": "42%",
      //"innerRadius": "60%",
      "pullOutRadius": 0,
      "colors": [
        '#5abc55',
        '#dd123d',
        '#538bb3'
      ],
      "labelText": "[[title]] [[percents]]% "
    });
	}

	function drawStackedAreaChart(docType, view, divId, chartTitle, graphConfig) {
    return AmCharts.makeChart(divId, {
      "dataLoader": {
        "url": "/graphs/" + docType + "/" + view
      },
      "type": "serial",
      "theme": "light",
      "marginRight":30,
      "legend": {
        "equalWidths": false,
        "periodValueText": "total: [[value.sum]]",
        "position": "top",
        "valueAlign": "left",
        "valueWidth": 100
      },
      "valueAxes": [{
        "stackType": "regular",
        "gridAlpha": 0.07,
        "position": "left",
        "title": "Activity"
      }],
      "titles": [
        {
          "text": chartTitle,
          "size": 15
        }
      ],
      "graphs": graphConfig,
      "plotAreaBorderAlpha": 0,
      "marginTop": 10,
      "marginLeft": 0,
      "marginBottom": 0,
      "chartScrollbar": {},
      "chartCursor": {
        "cursorAlpha": 0
      },
      "categoryField": "date",
      "categoryAxis": {
        "startOnAxis": true,
        "axisColor": "#DADADA",
        "gridAlpha": 0.07,
        "title": "Year",
        "dateFormats":[{
          period: 'fff',
          format: 'JJ:NN:SS'
        }, {
          period: 'ss',
          format: 'JJ:NN:SS'
        }, {
          period: 'mm',
          format: 'JJ:NN'
        }, {
          period: 'hh',
          format: 'JJ:NN'
        }, {
          period: 'DD',
          format: 'DD'
        }, {
          period: 'WW',
          format: 'DD'
        }, {
          period: 'MM',
          format: 'MMM'
        }, {
          period: 'YYYY',
          format: 'YYYY'
        }]
      },
      "export": {
        "enabled": true
      }
    });
	}

	/**
	 * UTILITY
	 */

	$('.expandable').each(function(index, expandContainer) {
		$(expandContainer).attr('data-origheight', $(expandContainer).height());
		if($(expandContainer).height() > 120) {
			$(expandContainer).height(120);
			$(expandContainer).next('.moreBtn').bind('click', function() {
				$(this).toggleClass('label-success');
				$(this).toggleClass('label-danger');
				var clicks = $(this).data('clicks');
				if (clicks) {
					$(this).html('...more');
					$(expandContainer).animate({height: 120}, 200);
				} else {
					$(this).html('...less');
					$(expandContainer).animate({height: $(expandContainer).attr('data-origheight')}, 200);
				}
				$(this).data("clicks", !clicks);
			});
			$(expandContainer).next('.moreBtn').css('display', 'inline');
		}
	});

});