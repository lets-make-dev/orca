package main

import (
	"testing"
)

func TestBuildAdjustedMouseSeq_SubtractsOneFromRow(t *testing.T) {
	tests := []struct {
		name       string
		button     int
		col        int
		row        int
		terminator byte
		want       string
	}{
		{"row 2 becomes 1 (press)", 0, 10, 2, 'M', "\033[<0;10;1M"},
		{"row 5 becomes 4 (press)", 0, 25, 5, 'M', "\033[<0;25;4M"},
		{"row 10 becomes 9 (release)", 0, 15, 10, 'm', "\033[<0;15;9m"},
		{"scroll up on row 3", 64, 5, 3, 'M', "\033[<64;5;2M"},
		{"scroll down on row 3", 65, 5, 3, 'M', "\033[<65;5;2M"},
		{"right-click row 4", 2, 20, 4, 'M', "\033[<2;20;3M"},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			got := string(buildAdjustedMouseSeq(tc.button, tc.col, tc.row, tc.terminator))
			if got != tc.want {
				t.Errorf("got %q, want %q", got, tc.want)
			}
		})
	}
}

func TestBuildAdjustedMouseSeq_ClampsRowToOne(t *testing.T) {
	tests := []struct {
		name string
		row  int
		want string
	}{
		{"row 1 clamps to 1", 1, "\033[<0;1;1M"},
		{"row 0 clamps to 1", 0, "\033[<0;1;1M"},
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			got := string(buildAdjustedMouseSeq(0, 1, tc.row, 'M'))
			if got != tc.want {
				t.Errorf("got %q, want %q", got, tc.want)
			}
		})
	}
}

func TestBuildAdjustedMouseSeq_PreservesTerminator(t *testing.T) {
	press := string(buildAdjustedMouseSeq(0, 5, 3, 'M'))
	release := string(buildAdjustedMouseSeq(0, 5, 3, 'm'))

	if press[len(press)-1] != 'M' {
		t.Errorf("press terminator: got %c, want M", press[len(press)-1])
	}
	if release[len(release)-1] != 'm' {
		t.Errorf("release terminator: got %c, want m", release[len(release)-1])
	}
}
