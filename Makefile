.PHONY: acceptance-up acceptance-test acceptance-down acceptance-reset

acceptance-up:
	./acceptance/bin/up.sh

acceptance-test:
	./acceptance/bin/test.sh

acceptance-down:
	./acceptance/bin/down.sh

acceptance-reset: acceptance-down acceptance-up
