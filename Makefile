# Air Defender's compiled part (DeadZones), built on the FPP it is installed on by
# scripts/fpp_install.sh, and again by FPP itself when FPP is upgraded.
# Plane safety is all PHP and does not need it: if this doesn't build, only DeadZones is missing.
SRCDIR ?= /opt/fpp/src
include ${SRCDIR}/makefiles/common/setup.mk
include $(SRCDIR)/makefiles/platform/*.mk

all: libAirDefender.$(SHLIB_EXT)
debug: all

OBJECTS_AirDefender_so += src/AirDefenderPlugin.o
LIBS_AirDefender_so += -L${SRCDIR} -lfpp -ljsoncpp
CXXFLAGS_src/AirDefenderPlugin.o += -I${SRCDIR}

%.o: %.cpp Makefile src/DeadZonesCore.h src/DeadZonesConfig.h src/DeadZonesCheck.h src/AdWarnings.h
	$(CCACHE) $(CC) $(CFLAGS) $(CXXFLAGS) $(CXXFLAGS_$@) -c $< -o $@

libAirDefender.$(SHLIB_EXT): $(OBJECTS_AirDefender_so) ${SRCDIR}/libfpp.$(SHLIB_EXT)
	$(CCACHE) $(CC) -shared $(CFLAGS_$@) $(OBJECTS_AirDefender_so) $(LIBS_AirDefender_so) $(LDFLAGS) -o $@

clean:
	rm -f libAirDefender.so libAirDefender.dylib $(OBJECTS_AirDefender_so)
