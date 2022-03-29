FROM elasticsearch:7.16.3

RUN ./bin/elasticsearch-plugin list | grep -q analysis-stempel || ./bin/elasticsearch-plugin install analysis-stempel
